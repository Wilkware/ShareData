<?php

declare(strict_types=1);

/** General functions  */
require_once __DIR__ . '/../libs/_traits.php';

/** Namespaced traits */
use Wilkware\ShareData\DebugHelper;
use Wilkware\ShareData\FormatHelper;
use Wilkware\ShareData\FormHelper;

/**
 * class ShareDataConfigurator
 *
 * Shares variables and media objects between two or more Symcon instances
 * via MQTT. A single variable list with an explicit direction controls
 * whether each entry publishes, subscribes, or does both.
 *
 * Supported directions values per entry:
 *   publish           – local variable → MQTT only
 *   subscribe         – MQTT → local variable only
 *   publish+subscribe – bidirectional (with ping-pong protection)
 *
 * Variable entries additionally support a SyncOnUpdate flag:
 *   false (default) – publish only when the value actually changes
 *   true            – publish on every VM_UPDATE, even timestamp-only updates
 */
class ShareDataConfigurator extends IPSModuleStrict
{
    // -------------------------------------------------------------------------
    // Traits
    // -------------------------------------------------------------------------

    use DebugHelper;
    use FormatHelper;
    use FormHelper;

    // -------------------------------------------------------------------------
    // GUIDs
    // -------------------------------------------------------------------------

    /** GUID of the MQTT Client parent module (MQTT Server would be {C6D2AEB3-6E1F-4B2E-8E69-3C6D06849B4E}) */
    private const GUID_MQTT_IO = '{F7A0DD2E-7684-95C0-64C2-D2A9DC47577B}';

    /** GUID used when sending data to the MQTT Client parent */
    private const GUID_MQTT_TX = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}';

    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    /** Direction constant: local variable → MQTT. */
    private const SHARE_DIR_PUBLISH = 'publish';

    /** Direction constant: MQTT → local variable. */
    private const SHARE_DIR_SUBSCRIBE = 'subscribe';

    /** Direction constant: bidirectional. */
    private const SHARE_DIR_BOTH = 'publish+subscribe';

    /** Startup publish delay time */
    private const SHARE_STARTUP_DELAY = 30000;

    /** Publish delay (ms) after the parent became active again (reconnect) */
    private const SHARE_RECONNECT_DELAY = 5000;

    // -------------------------------------------------------------------------
    // Ping-pong guard
    // -------------------------------------------------------------------------
    //
    // MessageSink is NOT executed in the same PHP thread as SetValue /
    // RequestAction / IPS_SetMediaContent, so a static array is not visible
    // there. Instance buffers are shared between all threads of the instance
    // and are therefore used for both guards:
    //
    //   MqttWrite_<ID>  – hash + timestamp of the value last written via MQTT;
    //                     the one update carrying exactly this value is not
    //                     re-published (consumed once, expires after
    //                     SHARE_WRITE_TIMEOUT). Other values are always published.
    //   Out_<md5 topic> – hash + timestamp of the last own publish per topic;
    //                     the broker echo (MQTT Client subscribes '#') is
    //                     swallowed once within SHARE_ECHO_WINDOW.

    /** Lifetime (s) of an unconsumed MQTT write marker (e.g. device without feedback). */
    private const SHARE_WRITE_TIMEOUT = 30.0;

    /** Window (s) in which an identical incoming message counts as own echo. */
    private const SHARE_ECHO_WINDOW = 5.0;

    /** Maximum payload length in debug output. */
    private const SHARE_DEBUG_MAXLEN = 200;

    // -------------------------------------------------------------------------
    // Methods
    // -------------------------------------------------------------------------

    /**
     * In contrast to Construct, this function is called only once when creating the instance and starting Symcon.
     * Therefore, status variables and module properties which the module requires permanently should be created here.
     *
     * @return void
     */
    public function Create(): void
    {
        parent::Create();

        if ((float) IPS_GetKernelVersion() < 8.2) {
            $this->ConnectParent(self::GUID_MQTT_IO);
        }

        // --- Properties ------------------------------------------------------

        /**
         * JSON array of variable entries.
         * Schema per element:
         *   VariableID    int     IPS variable ID
         *   Topic         string  MQTT sub-topic (without prefix)
         *   Direction     string  "publish" | "subscribe" | "publish+subscribe"
         *   SyncOnUpdate  bool    when true, publish on every VM_UPDATE regardless
         *                         of whether the value changed
         */
        $this->RegisterPropertyString('Variables', '[]');

        /**
         * JSON array of media entries.
         * Schema per element:
         *   MediaID    int     IPS media object ID
         *   Topic      string  MQTT sub-topic (without prefix)
         *   Direction  string  "publish" | "subscribe" | "publish+subscribe"
         */
        $this->RegisterPropertyString('Media', '[]');

        /** Common topic prefix prepended to every sub-topic. */
        $this->RegisterPropertyString('TopicPrefix', 'symcon/share/');

        /** When true, all publish-capable objects are sent immediately on ApplyChanges. */
        $this->RegisterPropertyBoolean('PublishOnConnect', true);

        // --- Timer -----------------------------------------------------------

        /** Start-up delay timer */
        $this->RegisterTimer('StartupPublish', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "publish", "");');
    }

    /**
     * This function is called when deleting the instance during operation and when updating via "Module Control".
     * The function is not called when exiting Symcon.
     *
     * @return void
     */
    public function Destroy(): void
    {
        parent::Destroy();
    }

    /**
     * The content can be overwritten in order to transfer a self-created configuration page.
     * This way, content can be generated dynamically.
     * In this case, the "form.json" on the file system is completely ignored.
     *
     * @return string Content of the configuration page.
     */
    public function GetConfigurationForm(): string
    {
        // Get Form
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        // Extract Version
        $instance = IPS_GetInstance($this->InstanceID);
        $modul = IPS_GetModule($instance['ModuleInfo']['ModuleID']);
        $library = IPS_GetLibrary($modul['LibraryID']);
        $version = sprintf('v%s.%d', $library['Version'], $library['Build']);
        $this->ModifyFormElement($form['actions'], 'Version', function (array &$element) use ($version): void
        {
            $element['caption'] = $version;
        });

        // Debug output
        //$this->LogDebug(__FUNCTION__, $form);
        return json_encode($form);
    }

    /**
     * Is executed when "Apply" is pressed on the configuration page and immediately after the instance has been created.
     *
     * @return void
     */
    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Only messages whose Topic starts with the prefix. The prefix is quoted
        // for the regex, slashes may appear JSON-escaped ("\/") in the data flow.
        $prefix = $this->ReadPropertyString('TopicPrefix');
        $filter = '.*"Topic" *: *"' . str_replace('/', '\\\\?/', preg_quote($prefix)) . '.*';
        $this->SetReceiveDataFilter($filter);
        $this->LogDebug(__FUNCTION__, 'SetReceiveDataFilter(' . $filter . ')');

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);

        // Monitor connect/disconnect of the parent (FM_*) and its status (IM_CHANGESTATUS)
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);

        // Skip initialization when the kernel is not yet ready.
        // KR_READY will trigger UpdateRegistrations + parent monitoring + publish.
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $this->CheckConfiguration();
        $this->UpdateRegistrations();
        $this->RegisterParent();
        $this->UpdateStatus();

        if ($this->ReadPropertyBoolean('PublishOnConnect')) {
            $this->SetTimerInterval('StartupPublish', self::SHARE_STARTUP_DELAY);
        } else {
            $this->SetTimerInterval('StartupPublish', 0);
        }
    }

    /**
     * Is called when, for example, a button is clicked in the visualization.
     *
     * @param string $ident Ident of the variable
     * @param mixed $value The value to be set
     *
     * @return void
     */
    public function RequestAction(string $ident, mixed $value): void
    {
        // Debug output
        $this->LogDebug(__FUNCTION__, $ident . ' => ' . $value);
        switch ($ident) {
            case 'publish':
                $this->PublishAllObjects();
                break;
            default:
                break;
        }
    }

    /**
     * The content of the function can be overwritten in order to carry out own reactions to certain messages.
     * The function is only called for registered MessageIDs/SenderIDs combinations.
     *
     * data[0] = new value
     * data[1] = value changed?
     * data[2] = old value
     * data[3] = timestamp.
     *
     * @param int   $timestamp Continuous counter timestamp
     * @param int   $sender    Sender ID
     * @param int   $message   ID of the message
     * @param array{0:mixed,1:bool,2:mixed,3:int} $data Data of the message
     *
     * @return void
     */
    public function MessageSink(int $timestamp, int $sender, int $message, array $data): void
    {
        switch ($message) {
            case IPS_KERNELMESSAGE:
                if ($data[0] === KR_READY) {
                    $this->UpdateRegistrations();
                    $this->RegisterParent();
                    $this->UpdateStatus();

                    if ($this->ReadPropertyBoolean('PublishOnConnect')) {
                        $this->SetTimerInterval('StartupPublish', self::SHARE_STARTUP_DELAY);
                    }
                }
                break;

            case FM_CONNECT:
            case FM_DISCONNECT:
                // Parent instance was changed
                $this->RegisterParent();
                $this->UpdateStatus();
                break;

            case IM_CHANGESTATUS:
                // Status of the parent (MQTT Client) changed
                if ($sender !== (int) $this->GetBuffer('ParentID')) {
                    break;
                }
                $active = ($data[0] === IS_ACTIVE);
                $this->UpdateStatus($active);
                if ($active && $this->ReadPropertyBoolean('PublishOnConnect') && (IPS_GetKernelRunlevel() === KR_READY)) {
                    $this->LogDebug(__FUNCTION__, 'Parent active again – publish all objects in ' . self::SHARE_RECONNECT_DELAY . ' ms');
                    $this->SetTimerInterval('StartupPublish', self::SHARE_RECONNECT_DELAY);
                }
                break;

            case VM_UPDATE:
                $this->HandleVariableUpdate($sender, $data[0], (bool) $data[1]);
                break;

            case MM_UPDATE:
                // only when content changed (not just metadata)
                if ($data[0]) {
                    $this->HandleMediaUpdate($sender);
                }
                break;
        }
    }

    /**
     * This function is called by Symcon and processes sent data and, if necessary, forwards it to
     * all child instances. Data can be sent using the SendDataToChildren function.
     *
     * @param string $json Data package in JSON format
     *
     * @return string Optional response to the parent instance
     */
    public function ReceiveData(string $json): string
    {
        $data = json_decode($json, true);

        if (!isset($data['Topic'], $data['Payload'])) {
            return '';
        }
        // Validate before hex2bin() to avoid PHP warnings on invalid input
        $hex = (string) $data['Payload'];
        $valid = (strlen($hex) % 2 === 0) && (strspn($hex, '0123456789abcdefABCDEF') === strlen($hex));
        $payload = $valid ? hex2bin($hex) : false;
        if ($payload === false) {
            $this->LogDebug(__FUNCTION__, sprintf('SKIP Topic: %s | payload is not valid hex', $data['Topic']));
            return '';
        }

        $this->LogDebug(__FUNCTION__, sprintf('Topic: %s | Payload: %s', $data['Topic'], $this->ShortenPayload($payload)));
        $this->HandleMQTTMessage($data['Topic'], $payload);

        return '';
    }

    /**
     * Handles a VM_UPDATE notification for a registered variable.
     *
     * Respects the SyncOnUpdate flag per entry:
     *   - SyncOnUpdate = false → publish only when $valueChanged is true
     *   - SyncOnUpdate = true  → publish on every call, even timestamp-only updates
     *
     * Uses the value carried by the message (VM_UPDATE $Data[0]) instead of
     * GetValue(), because messages are processed asynchronously and the
     * variable may already hold a newer value.
     *
     * @param int   $id      IPS variable ID
     * @param mixed $value   Value of this update (VM_UPDATE $Data[0])
     * @param bool  $changed True when the value actually changed (VM_UPDATE $Data[1])
     *
     * @return void
     */
    private function HandleVariableUpdate(int $id, mixed $value, bool $changed): void
    {
        $payload = $this->ValueToPayload($id, $value);

        if ($this->IsMqttWrite($id, $payload)) {
            $this->LogDebug(__FUNCTION__, sprintf('SKIP Variable %d was written by MQTT – skipping publish', $id));
            return;
        }

        $prefix = $this->ReadPropertyString('TopicPrefix');
        $variables = json_decode($this->ReadPropertyString('Variables'), true);

        foreach ($variables as $entry) {
            if ((int) $entry['VariableID'] !== $id) {
                continue;
            }

            if (!in_array($entry['Direction'], [self::SHARE_DIR_PUBLISH, self::SHARE_DIR_BOTH], true)) {
                return;
            }

            $sync = (bool) ($entry['SyncOnUpdate'] ?? false);

            if (!$changed && !$sync) {
                $this->LogDebug(__FUNCTION__, sprintf('SKIP Variable %d: value unchanged and SyncOnUpdate is off', $id));
                return;
            }

            $this->PublishMQTT($prefix . $entry['Topic'], $payload);

            return;
        }
    }

    /**
     * Handles a MM_UPDATE notification for a registered media object.
     *
     * @param int $id IPS media object ID
     *
     * @return void
     */
    private function HandleMediaUpdate(int $id): void
    {
        if ($this->IsMqttWrite($id, (string) base64_decode(IPS_GetMediaContent($id)))) {
            $this->LogDebug(__FUNCTION__, sprintf('SKIP Media %d was written by MQTT – skipping publish', $id));
            return;
        }

        $prefix = $this->ReadPropertyString('TopicPrefix');
        $media = json_decode($this->ReadPropertyString('Media'), true);

        foreach ($media as $entry) {
            if ((int) $entry['MediaID'] !== $id) {
                continue;
            }

            if (!in_array($entry['Direction'], [self::SHARE_DIR_PUBLISH, self::SHARE_DIR_BOTH], true)) {
                return;
            }

            // IPS_GetMediaContent() already returns Base64 → send as is
            $this->PublishMQTT($prefix . $entry['Topic'], IPS_GetMediaContent($id));

            return;
        }
    }

    /**
     * Routes an incoming MQTT message to the matching local variable or media object.
     *
     * Checks the Variables list first, then the Media list.
     * Only entries with direction "subscribe" or "publish+subscribe" are considered.
     *
     * @param string $topic     Full topic string including the configured prefix
     * @param string $payload   Raw payload string
     *
     * @return void
     */
    private function HandleMQTTMessage(string $topic, string $payload): void
    {
        $prefix = $this->ReadPropertyString('TopicPrefix');

        if (!str_starts_with($topic, $prefix)) {
            return;
        }

        if ($this->IsOwnEcho($topic, $payload)) {
            $this->LogDebug(__FUNCTION__, sprintf('SKIP Topic %s: echo of own publish', $topic));
            return;
        }

        $topic = substr($topic, strlen($prefix));

        // --- Variables -------------------------------------------------------
        $variables = json_decode($this->ReadPropertyString('Variables'), true);
        foreach ($variables as $entry) {
            if ($entry['Topic'] !== $topic) {
                continue;
            }
            if (!in_array($entry['Direction'], [self::SHARE_DIR_SUBSCRIBE, self::SHARE_DIR_BOTH], true)) {
                continue;
            }
            $id = (int) $entry['VariableID'];
            $syncOnUpdate = (bool) ($entry['SyncOnUpdate'] ?? false);
            if (IPS_VariableExists($id)) {
                $this->SetVariableFromMQTT($id, $payload, $syncOnUpdate);
            }
            return;
        }

        // --- Media -----------------------------------------------------------
        $medialist = json_decode($this->ReadPropertyString('Media'), true);
        foreach ($medialist as $entry) {
            if ($entry['Topic'] !== $topic) {
                continue;
            }
            if (!in_array($entry['Direction'], [self::SHARE_DIR_SUBSCRIBE, self::SHARE_DIR_BOTH], true)) {
                continue;
            }
            $id = (int) $entry['MediaID'];
            if (IPS_MediaExists($id)) {
                $this->SetMediaFromMQTT($id, $payload);
            }
            return;
        }
    }

    /**
     * Writes a converted MQTT payload value to a local IPS variable.
     *
     * Uses RequestAction() when the variable has a linked action (e.g. a
     * device actor), otherwise falls back to SetValue() for pure data variables.
     *
     * When $syncOnUpdate is false the write is skipped if the current value
     * already matches the incoming payload (default behaviour).
     * When $syncOnUpdate is true the write always proceeds, which allows the
     * subscriber to react to every incoming message as an event even when the
     * value has not changed.
     *
     * @param int    $id           Target IPS variable ID
     * @param string $payload      Raw MQTT payload string
     * @param bool   $syncOnUpdate When true, write even if the value is unchanged
     *
     * @return void
     */
    private function SetVariableFromMQTT(int $id, string $payload, bool $syncOnUpdate = false): void
    {
        $variable = IPS_GetVariable($id);
        $value = $this->PayloadToValue($payload, $variable['VariableType']);

        if (!$syncOnUpdate && GetValue($id) === $value) {
            $this->LogDebug(__FUNCTION__, sprintf('SKIP Variable %d already has the target value – skipping set', $id));
            return;
        }

        // Mark before writing – the resulting VM_UPDATE is processed in another thread
        $this->MarkMqttWrite($id, $this->ValueToPayload($id, $value));

        try {
            if (HasAction($id)) {
                $this->LogDebug(__FUNCTION__, sprintf('RequestAction on variable %d', $id));
                RequestAction($id, $value);
            } else {
                $this->LogDebug(__FUNCTION__, sprintf('SetValue on variable %d', $id));
                SetValue($id, $value);
            }
        } catch (\Throwable $e) {
            $this->LogMessage(sprintf('Error writing variable %d: %s', $id, $e->getMessage()), KL_ERROR);
        }
    }

    /**
     * Writes a Base64-encoded MQTT payload as content to a local IPS media object.
     *
     * @param int    $id      IPS media object ID
     * @param string $payload Base64-encoded content (as returned by IPS_GetMediaContent)
     *
     * @return void
     */
    private function SetMediaFromMQTT(int $id, string $payload): void
    {
        if ($payload !== '' && base64_decode($payload, true) === false) {
            $this->LogMessage(sprintf('Media %d: payload is not valid Base64 – skipping', $id), KL_WARNING);
            return;
        }

        // Mark before writing – the resulting MM_UPDATE is processed in another thread
        $this->MarkMqttWrite($id, (string) base64_decode($payload));

        try {
            $this->LogDebug(__FUNCTION__, sprintf('IPS_SetMediaContent on media %d', $id));
            IPS_SetMediaContent($id, $payload);
        } catch (\Throwable $e) {
            $this->LogMessage(sprintf('Error writing media %d: %s', $id, $e->getMessage()), KL_ERROR);
        }
    }

    /**
     * Remembers the value an object is being written with via MQTT (ping-pong guard).
     *
     * @param int    $id          IPS object ID
     * @param string $fingerprint Canonical value (variable: payload string, media: raw content)
     *
     * @return void
     */
    private function MarkMqttWrite(int $id, string $fingerprint): void
    {
        $this->SetBuffer('MqttWrite_' . $id, md5($fingerprint) . '|' . microtime(true));
    }

    /**
     * Checks whether an update of the object carries exactly the value that was
     * written via MQTT. A match consumes the marker, so only this one update is
     * suppressed. Local changes to any other value are always published.
     * Unconsumed markers expire after SHARE_WRITE_TIMEOUT seconds.
     *
     * @param int    $id          IPS object ID
     * @param string $fingerprint Canonical current value (see MarkMqttWrite)
     *
     * @return bool True if the update originates from the MQTT write
     */
    private function IsMqttWrite(int $id, string $fingerprint): bool
    {
        $key = 'MqttWrite_' . $id;
        $buffer = $this->GetBuffer($key);
        if ($buffer === '') {
            return false;
        }
        [$hash, $ts] = array_pad(explode('|', $buffer, 2), 2, '0');
        if ((microtime(true) - (float) $ts) >= self::SHARE_WRITE_TIMEOUT) {
            $this->SetBuffer($key, '');
            return false;
        }
        if ($hash !== md5($fingerprint)) {
            return false;
        }
        $this->SetBuffer($key, '');
        return true;
    }

    /**
     * Remembers hash and time of the last own publish per topic (echo filter).
     *
     * @param string $topic   Full topic string
     * @param string $payload Published payload
     *
     * @return void
     */
    private function MarkPublished(string $topic, string $payload): void
    {
        $this->SetBuffer('Out_' . md5($topic), md5($payload) . '|' . microtime(true));
    }

    /**
     * Checks whether an incoming message is the broker echo of the own last publish.
     * A detected echo is consumed, so only the first identical message is swallowed.
     *
     * @param string $topic   Full topic string
     * @param string $payload Received payload
     *
     * @return bool True if the message is the own echo
     */
    private function IsOwnEcho(string $topic, string $payload): bool
    {
        $key = 'Out_' . md5($topic);
        $buffer = $this->GetBuffer($key);
        if ($buffer === '') {
            return false;
        }
        [$hash, $ts] = array_pad(explode('|', $buffer, 2), 2, '0');
        if (((microtime(true) - (float) $ts) >= self::SHARE_ECHO_WINDOW) || ($hash !== md5($payload))) {
            return false;
        }
        $this->SetBuffer($key, '');
        return true;
    }

    /**
     * Shortens a payload for debug output (media payloads can be very large).
     *
     * @param string $payload Payload
     *
     * @return string Shortened payload
     */
    private function ShortenPayload(string $payload): string
    {
        $length = strlen($payload);
        if ($length <= self::SHARE_DEBUG_MAXLEN) {
            return $payload;
        }
        return substr($payload, 0, self::SHARE_DEBUG_MAXLEN) . sprintf('… (%d bytes)', $length);
    }

    /**
     * Converts an IPS variable value to a plain-text MQTT payload string.
     *
     * @param int   $id         Source variable (used to determine the type)
     * @param mixed $value      Current variable value
     *
     * @return string Serialized payload
     */
    private function ValueToPayload(int $id, mixed $value): string
    {
        return match (IPS_GetVariable($id)['VariableType']) {
            VARIABLETYPE_BOOLEAN => $value ? 'true' : 'false',
            VARIABLETYPE_INTEGER => (string) (int) $value,
            // json_encode uses serialize_precision (-1) → shortest exact representation
            VARIABLETYPE_FLOAT   => is_finite((float) $value) ? (string) json_encode((float) $value) : (string) (float) $value,
            default              => (string) $value,
        };
    }

    /**
     * Converts a raw MQTT payload string to a typed PHP value suitable for
     * the target variable type.
     *
     * Boolean detection accepts: true / false / 1 / 0 / on / off / yes / no
     *
     * @param string $payload Raw MQTT payload
     * @param int    $type    IPS VARIABLETYPE_* constant
     *
     * @return bool|int|float|string
     */
    private function PayloadToValue(string $payload, int $type): mixed
    {
        return match ($type) {
            VARIABLETYPE_BOOLEAN => in_array(strtolower($payload), ['true', '1', 'on', 'yes'], true),
            VARIABLETYPE_INTEGER => (int) $payload,
            VARIABLETYPE_FLOAT   => (float) $payload,
            default              => $payload,
        };
    }

    /**
     * Publishes a single message to the MQTT parent.
     *
     * The payload is transferred as a hex string over the IPS data pipe
     * (bin2hex on send, hex2bin on receive).
     *
     * @param string $topic   Full topic string (prefix already included)
     * @param string $payload Plain-text or binary payload
     * @param bool   $retain  Whether the broker should retain the message (default: true)
     */
    private function PublishMQTT(string $topic, string $payload, bool $retain = true): void
    {
        if (!$this->HasActiveParent()) {
            $this->LogDebug(__FUNCTION__, 'SKIP – no active parent');
            return;
        }

        $this->LogDebug(__FUNCTION__, sprintf('Topic: %s | Payload: %s', $topic, $this->ShortenPayload($payload)));

        // Remember own publish, the broker sends it back to us (echo filter)
        $this->MarkPublished($topic, $payload);

        $this->SendDataToParent(json_encode([
            'DataID'           => self::GUID_MQTT_TX,
            'PacketType'       => 3,
            'QualityOfService' => 0,
            'Topic'            => $topic,
            'Payload'          => bin2hex($payload),
            'Retain'           => $retain,
        ], JSON_UNESCAPED_SLASHES));
    }

    /**
     * Publishes the current value of every object with direction
     * "publish" or "publish+subscribe" to the MQTT broker.
     *
     * @return void
     */
    private function PublishAllObjects(): void
    {
        $this->LogMessage('Publishing all configured variables and media objects to MQTT', KL_NOTIFY);
        $this->SetTimerInterval('StartupPublish', 0);
        $prefix = $this->ReadPropertyString('TopicPrefix');
        $variables = json_decode($this->ReadPropertyString('Variables'), true);
        $media = json_decode($this->ReadPropertyString('Media'), true);

        foreach ($variables as $entry) {
            if (!in_array($entry['Direction'], [self::SHARE_DIR_PUBLISH, self::SHARE_DIR_BOTH], true)) {
                continue;
            }
            $id = (int) $entry['VariableID'];
            if (!IPS_VariableExists($id)) {
                continue;
            }
            $this->PublishMQTT(
                $prefix . $entry['Topic'],
                $this->ValueToPayload($id, GetValue($id))
            );
        }

        foreach ($media as $entry) {
            if (!in_array($entry['Direction'], [self::SHARE_DIR_PUBLISH, self::SHARE_DIR_BOTH], true)) {
                continue;
            }
            $id = (int) $entry['MediaID'];
            if (!IPS_MediaExists($id)) {
                continue;
            }
            // IPS_GetMediaContent() already returns Base64 → send as is
            $this->PublishMQTT(
                $prefix . $entry['Topic'],
                IPS_GetMediaContent($id)
            );
        }
    }

    /**
     * Checks the configuration for empty and duplicate topics.
     * Duplicate subscribe topics are ambiguous (only the first matching entry
     * is written), duplicate publish topics overwrite each other on the broker.
     * Problems are reported as warnings in the message log.
     *
     * @return void
     */
    private function CheckConfiguration(): void
    {
        $lists = [
            'Variable' => ['entries' => json_decode($this->ReadPropertyString('Variables'), true) ?? [], 'key' => 'VariableID'],
            'Media'    => ['entries' => json_decode($this->ReadPropertyString('Media'), true) ?? [], 'key' => 'MediaID'],
        ];

        $seen = [];
        foreach ($lists as $type => $list) {
            foreach ($list['entries'] as $entry) {
                $id = (int) ($entry[$list['key']] ?? 0);
                $topic = trim((string) ($entry['Topic'] ?? ''));
                if ($topic === '') {
                    $this->LogMessage(sprintf('%s %d has no topic – entry is ignored', $type, $id), KL_WARNING);
                    continue;
                }
                if (isset($seen[$topic])) {
                    $this->LogMessage(sprintf('Topic "%s" is used more than once (%s and %s %d) – please use unique topics', $topic, $seen[$topic], $type, $id), KL_WARNING);
                    continue;
                }
                $seen[$topic] = sprintf('%s %d', $type, $id);
            }
        }
    }

    /**
     * Registers IM_CHANGESTATUS for the current parent instance and
     * unregisters it for a previous parent.
     *
     * @return void
     */
    private function RegisterParent(): void
    {
        $old = (int) $this->GetBuffer('ParentID');
        $new = (int) IPS_GetInstance($this->InstanceID)['ConnectionID'];

        if ($old === $new) {
            return;
        }
        if ($old > 0) {
            $this->UnregisterMessage($old, IM_CHANGESTATUS);
        }
        if ($new > 0) {
            $this->RegisterMessage($new, IM_CHANGESTATUS);
        }
        $this->SetBuffer('ParentID', (string) $new);
        $this->LogDebug(__FUNCTION__, sprintf('Parent: %d → %d', $old, $new));
    }

    /**
     * Sets the instance status depending on the parent state
     * (102 = active, 104 = not connected).
     *
     * @param bool|null $active Known parent state or null to determine it
     *
     * @return void
     */
    private function UpdateStatus(?bool $active = null): void
    {
        $active ??= $this->HasActiveParent();
        $status = $active ? IS_ACTIVE : IS_INACTIVE;
        if ($this->GetStatus() !== $status) {
            $this->SetStatus($status);
        }
        $this->LogDebug(__FUNCTION__, 'Status: ' . $status);
    }

    /**
     * Clears all previously registered VM_UPDATE / MM_UPDATE listeners and
     * re-registers only the IDs relevant to the current configuration.
     *
     * Variables with direction "publish" or "publish+subscribe" are registered
     * for VM_UPDATE. Media objects likewise for MM_UPDATE.
     *
     * @return void
     */
    private function UpdateRegistrations(): void
    {
        foreach ($this->GetMessageList() as $sender => $messages) {
            if ($sender === 0) {
                continue;
            }
            if (in_array(VM_UPDATE, $messages, true)) {
                $this->UnregisterMessage($sender, VM_UPDATE);
            }
            if (in_array(MM_UPDATE, $messages, true)) {
                $this->UnregisterMessage($sender, MM_UPDATE);
            }
        }

        $variables = json_decode($this->ReadPropertyString('Variables'), true);
        foreach ($variables as $entry) {
            if (!in_array($entry['Direction'], [self::SHARE_DIR_PUBLISH, self::SHARE_DIR_BOTH], true)) {
                continue;
            }
            $id = (int) $entry['VariableID'];
            if (IPS_VariableExists($id)) {
                $this->RegisterMessage($id, VM_UPDATE);
            }
        }

        $medialist = json_decode($this->ReadPropertyString('Media'), true);
        foreach ($medialist as $entry) {
            if (!in_array($entry['Direction'], [self::SHARE_DIR_PUBLISH, self::SHARE_DIR_BOTH], true)) {
                continue;
            }
            $id = (int) $entry['MediaID'];
            if (IPS_MediaExists($id)) {
                $this->RegisterMessage($id, MM_UPDATE);
            }
        }
    }
}