<?php

declare(strict_types=1);

// General functions
require_once __DIR__ . '/../libs/_traits.php';

/**
 * class ShareDataConfigurator
 *
 * Shares variables and media objects between two or more IP-Symcon instances
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
    use DebugHelper;
    use FormatHelper;
    use VariableHelper;

    // -------------------------------------------------------------------------
    // GUIDs
    // -------------------------------------------------------------------------

    /** GUID of the MQTT Client parent module */
    private const GUID_MQTT_IO = '{C6D2AEB3-6E1F-4B2E-8E69-3C6D06849B4E}';

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

    // -------------------------------------------------------------------------
    // Ping-pong guard
    // -------------------------------------------------------------------------

    /**
     * Tracks object IDs currently being written via MQTT so that the
     * resulting VM_UPDATE / MM_UPDATE message is not re-published back to
     * the broker.
     *
     * Uses a static array because the MessageSink callback runs synchronously
     * in the same PHP process as SetValue / RequestAction / IPS_SetMediaContent.
     *
     * @var array<int, true>
     */
    private static array $MQTT_WRITING = [];

    // -------------------------------------------------------------------------
    // Methods
    // -------------------------------------------------------------------------

    /**
     * In contrast to Construct, this function is called only once when creating the instance and starting IP-Symcon.
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

        // --- Attributes ------------------------------------------------------

        /** Persists the list of currently subscribed full topics. */
        $this->RegisterAttributeString('SubscribedTopics', '[]');
    }

    /**
     * This function is called when deleting the instance during operation and when updating via "Module Control".
     * The function is not called when exiting IP-Symcon.
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
        $form['actions'][1]['items'][2]['caption'] = sprintf('v%s.%d', $library['Version'], $library['Build']);

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

        $prefix = $this->ReadPropertyString('TopicPrefix');
        $this->SetReceiveDataFilter('.*' . $prefix . '.*');
        $this->LogDebug(__FUNCTION__, 'SetReceiveDataFilter(\'.*' . $prefix . '.*\')');

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);

        $this->UpdateRegistrations();
        $this->UpdateMQTTSubscriptions();

        if ($this->ReadPropertyBoolean('PublishOnConnect')) {
            $this->PublishAllObjects();
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
                    $this->UpdateMQTTSubscriptions();
                    $this->PublishAllObjects();
                }
                break;

            case VM_UPDATE:
                $this->HandleVariableUpdate($sender, (bool) $data[1]);
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
     * This function is called by IP-Symcon and processes sent data and, if necessary, forwards it to
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
        $payload = hex2bin($data['Payload']);

        $this->LogDebug(__FUNCTION__, sprintf('Topic: %s | Payload: %s', $data['Topic'], $payload));
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
     * @param int  $id          IPS variable ID
     * @param bool $changed     True when the value actually changed (VM_UPDATE $Data[1])
     *
     * @return void
     */
    private function HandleVariableUpdate(int $id, bool $changed): void
    {
        if (isset(self::$MQTT_WRITING[$id])) {
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

            $this->PublishMQTT(
                $prefix . $entry['Topic'],
                $this->ValueToPayload($id, GetValue($id))
            );

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
        if (isset(self::$MQTT_WRITING[$id])) {
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

            $this->PublishMQTT($prefix . $entry['Topic'], bin2hex(IPS_GetMediaContent($id)));

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

        $topic = substr($topic, strlen($prefix));

        // --- Variables -------------------------------------------------------
        $variables = json_decode($this->ReadPropertyString('Variables'), true);
        foreach ($variables as $entry) {
            if ($entry['Topic'] !== $topic) {
                continue;
            }
            if (!in_array($entry['Direction'], [self::SHARE_DIR_SUBSCRIBE, self::SHARE_DIR_BOTH], true)) {
                return;
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
                return;
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

        self::$MQTT_WRITING[$id] = true;

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
        } finally {
            IPS_Sleep(50);
            unset(self::$MQTT_WRITING[$id]);
        }
    }

    /**
     * Writes a hex-encoded MQTT payload as binary content to a local IPS media object.
     *
     * @param int    $id      IPS media object ID
     * @param string $payload Hex-encoded binary content
     *
     * @return void
     */
    private function SetMediaFromMQTT(int $id, string $payload): void
    {
        $content = hex2bin($payload);

        if ($content === false) {
            $this->LogMessage(sprintf('Media %d: payload is not valid hex – skipping', $id), KL_WARNING);
            return;
        }

        self::$MQTT_WRITING[$id] = true;

        try {
            $this->LogDebug(__FUNCTION__, sprintf('IPS_SetMediaContent on media %d', $id));
            IPS_SetMediaContent($id, $content);
        } catch (\Throwable $e) {
            $this->LogMessage(sprintf('Error writing media %d: %s', $id, $e->getMessage()), KL_ERROR);
        } finally {
            IPS_Sleep(50);
            unset(self::$MQTT_WRITING[$id]);
        }
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
            VARIABLETYPE_FLOAT   => (string) (float) $value,
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
        $this->LogDebug(__FUNCTION__, sprintf('Topic: %s | Payload: %s', $topic, $payload));

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
            $this->PublishMQTT(
                $prefix . $entry['Topic'],
                bin2hex(IPS_GetMediaContent($id))
            );
        }
    }

    /**
     * Subscribes to all topics required by the current configuration.
     * Only entries with direction "subscribe" or "publish+subscribe" are subscribed.
     *
     * @return void
     */
    private function UpdateMQTTSubscriptions(): void
    {
        $prefix = $this->ReadPropertyString('TopicPrefix');
        $variables = json_decode($this->ReadPropertyString('Variables'), true);
        $media = json_decode($this->ReadPropertyString('Media'), true);
        $topics = [];

        foreach (array_merge($variables, $media) as $entry) {
            $topic = $entry['Topic'] ?? '';
            if ($topic === '') {
                continue;
            }
            if (in_array($entry['Direction'], [self::SHARE_DIR_SUBSCRIBE, self::SHARE_DIR_BOTH], true)) {
                $topics[] = $prefix . $topic;
            }
        }

        $this->WriteAttributeString('SubscribedTopics', json_encode($topics));
        $this->LogDebug(__FUNCTION__, sprintf('%s', implode(', ', $topics)));
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
