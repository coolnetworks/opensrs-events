<?php

namespace Modules\OpenSrsEvents\Providers;

use Illuminate\Support\ServiceProvider;
use App\Conversation;

define('OPENSRSEVENTS_MODULE', 'opensrsevents');

class OpenSrsEventsServiceProvider extends ServiceProvider
{
    // Folder types — one per domain event category
    const FOLDERS = [
        'renew'    => ['type' => 121, 'name' => 'Domains / Renewals',      'icon' => 'refresh'],
        'transfer' => ['type' => 122, 'name' => 'Domains / Transfers',     'icon' => 'transfer'],
        'register' => ['type' => 123, 'name' => 'Domains / Registrations', 'icon' => 'plus-sign'],
        'expire'   => ['type' => 124, 'name' => 'Domains / Expiry',        'icon' => 'time'],
        'delete'   => ['type' => 125, 'name' => 'Domains / Deletions',     'icon' => 'trash'],
        'wdrp'     => ['type' => 126, 'name' => 'Domains / WDRP',          'icon' => 'envelope'],
        'other'    => ['type' => 127, 'name' => 'Domains / Other',         'icon' => 'globe'],
    ];

    // Map message_type (from MESSAGE_STATUS_CHANGE events) to category + label
    const MESSAGE_TYPES = [
        'reseller.renewal.confirmation'        => ['cat' => 'renew',    'label' => 'Renewal confirmation'],
        'reseller.renewal.reminder'            => ['cat' => 'renew',    'label' => 'Renewal reminder'],
        'reseller.renewal.notice'              => ['cat' => 'renew',    'label' => 'Renewal notice'],
        'registrant.renewal.reminder'          => ['cat' => 'renew',    'label' => 'Renewal reminder (registrant)'],
        'registrant.renewal.notice'            => ['cat' => 'renew',    'label' => 'Renewal notice (registrant)'],
        'reseller.expiry.notice'               => ['cat' => 'expire',   'label' => 'Expiry notice'],
        'registrant.expiry.notice'             => ['cat' => 'expire',   'label' => 'Expiry notice (registrant)'],
        'reseller.expiration.reminder'         => ['cat' => 'expire',   'label' => 'Expiration reminder'],
        'registrant.expiration.reminder'       => ['cat' => 'expire',   'label' => 'Expiration reminder (registrant)'],
        'reseller.registration.confirmation'   => ['cat' => 'register', 'label' => 'Registration confirmation'],
        'registrant.registration.confirmation' => ['cat' => 'register', 'label' => 'Registration confirmation (registrant)'],
        'reseller.transfer.confirmation'       => ['cat' => 'transfer', 'label' => 'Transfer confirmation'],
        'reseller.transfer.notice'             => ['cat' => 'transfer', 'label' => 'Transfer notice'],
        'registrant.transfer.confirmation'     => ['cat' => 'transfer', 'label' => 'Transfer confirmation (registrant)'],
        'registrant.transfer.notice'           => ['cat' => 'transfer', 'label' => 'Transfer notice (registrant)'],
        'reseller.deletion.notice'             => ['cat' => 'delete',   'label' => 'Deletion notice'],
        'registrant.deletion.notice'           => ['cat' => 'delete',   'label' => 'Deletion notice (registrant)'],
        'eu.wdrp.domain'                       => ['cat' => 'wdrp',     'label' => 'WDRP verification'],
        'registrant.wdrp.domain'               => ['cat' => 'wdrp',     'label' => 'WDRP verification (registrant)'],
        'registrant.verification'              => ['cat' => 'wdrp',     'label' => 'Registrant verification'],
        'registrant.verification.reminder'     => ['cat' => 'wdrp',     'label' => 'Registrant verification reminder'],
        'reseller.trade.confirmation'          => ['cat' => 'transfer', 'label' => 'Registrant change confirmation'],
        'registrant.trade.confirmation'        => ['cat' => 'transfer', 'label' => 'Registrant change confirmation (registrant)'],
    ];

    // Map event types to category + label (used when no message_type)
    const EVENT_TYPES = [
        'REGISTERED'                            => ['cat' => 'register', 'label' => 'Registered'],
        'RENEWED'                               => ['cat' => 'renew',    'label' => 'Renewed'],
        'EXPIRED'                               => ['cat' => 'expire',   'label' => 'Expired'],
        'DELETED'                               => ['cat' => 'delete',   'label' => 'Deleted'],
        'STATUS_CHANGE'                         => ['cat' => 'other',    'label' => 'Status change'],
        'NAMESERVER_UPDATE'                     => ['cat' => 'other',    'label' => 'Nameserver update'],
        'ZONE_CHECK_STATUS_CHANGE'              => ['cat' => 'other',    'label' => 'Zone check'],
        'CLAIM_STATUS_CHANGE'                   => ['cat' => 'other',    'label' => 'Claim status change'],
        'REGISTRANT_VERIFICATION_STATUS_CHANGE' => ['cat' => 'wdrp',     'label' => 'Registrant verification'],
        'ICANN_TRADE_STATUS_CHANGE'             => ['cat' => 'transfer', 'label' => 'Registrant change'],
        'MESSAGE_STATUS_CHANGE'                 => ['cat' => 'other',    'label' => 'Message status change'],
    ];

    // Map ORDER object's order_reg_type to category
    const ORDER_REG_TYPES = [
        'new'             => ['cat' => 'register', 'label' => 'New registration order'],
        'renewal'         => ['cat' => 'renew',    'label' => 'Renewal order'],
        'transfer'        => ['cat' => 'transfer', 'label' => 'Transfer order'],
        'whois_privacy'   => ['cat' => 'other',    'label' => 'WHOIS privacy order'],
        'change_contact'  => ['cat' => 'transfer', 'label' => 'Contact change order'],
    ];

    public function boot()
    {
        $this->registerConfig();
        $this->registerHooks();
        $this->registerFolderHooks();
        $this->ensureDomainsFolders();
    }

    public function register()
    {
        //
    }

    protected function registerConfig()
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/config.php', 'opensrsevents');
    }

    protected function registerHooks()
    {
        \Eventy::addFilter('fetch_emails.data_to_save', function ($data) {
            // Only process emails from OpenSRS
            $from = $data['from'] ?? '';
            if (stripos($from, 'opensrs') === false && stripos($from, 'tucows') === false) {
                return $data;
            }

            // Try to extract JSON from the event_data.json attachment
            $json = null;
            $attachments = $data['attachments'] ?? [];
            foreach ($attachments as $attachment) {
                $name = '';
                if (method_exists($attachment, 'getName')) {
                    $name = $attachment->getName();
                }
                if (stripos($name, 'event_data') !== false && stripos($name, '.json') !== false) {
                    $content = '';
                    if (method_exists($attachment, 'getContent')) {
                        $content = $attachment->getContent();
                    }
                    $json = json_decode($content, true);
                    if ($json && isset($json['event'])) {
                        break;
                    }
                    $json = null;
                }
            }

            // Fallback: try body (in case JSON is inline)
            if (!$json) {
                $json = $this->extractJsonFromBody($data['body'] ?? '');
            }

            if (!$json) {
                return $data;
            }

            $objectData = $json['object_data'] ?? [];
            $domain = $objectData['domain_name'] ?? 'unknown';
            $event = $json['event'] ?? '';
            $object = $json['object'] ?? '';
            $messageType = $objectData['message_type'] ?? '';
            $orderRegType = $objectData['order_reg_type'] ?? '';
            $orderStatus = $objectData['order_status'] ?? '';

            // Determine category and label
            $category = $this->resolveCategory($event, $messageType, $orderRegType, $object);
            $label = $this->resolveLabel($event, $messageType, $orderRegType, $orderStatus, $object);

            // Subject: [dns:renew] example.com — Renewed
            $data['subject'] = '[dns:' . $category . '] ' . $domain . ' — ' . $label;

            // Replace body with readable summary
            $data['body'] = $this->buildReadableBody($json);

            // Drop the JSON attachment (info is now in the body)
            $data['attachments'] = [];

            // Thread by domain + category
            if (empty($data['prev_thread'])) {
                $prev = $this->findExistingDomainThread($domain, $category);
                if ($prev) {
                    $data['prev_thread'] = $prev;
                }
            }

            return $data;
        }, 20, 1);

        // Suppress auto-reply on conversation creation
        \Eventy::addAction('conversation.created_by_customer', function ($conversation, $thread, $customer) {
            if (preg_match('/^\[dns:/', $conversation->subject)) {
                $conversation->auto_reply_sent = true;
                $conversation->save();
            }
        }, 20, 3);
    }

    protected function registerFolderHooks()
    {
        $folderTypes = array_column(self::FOLDERS, 'type');

        \Eventy::addFilter('mailbox.folders.public_types', function ($list) use ($folderTypes) {
            return array_merge($list, $folderTypes);
        }, 20, 1);

        \Eventy::addFilter('folder.type_name', function ($name, $folder) {
            foreach (self::FOLDERS as $info) {
                if ($folder->type == $info['type']) {
                    return $info['name'];
                }
            }
            return $name;
        }, 20, 2);

        \Eventy::addFilter('folder.type_icon', function ($icon, $folder) {
            foreach (self::FOLDERS as $info) {
                if ($folder->type == $info['type']) {
                    return $info['icon'];
                }
            }
            return $icon;
        }, 20, 2);

        \Eventy::addFilter('folder.conversations_query', function ($query, $folder, $user_id) {
            $catKey = $this->folderTypeToCategory($folder->type);

            if ($catKey !== null) {
                return Conversation::where('mailbox_id', $folder->mailbox_id)
                    ->where('state', Conversation::STATE_PUBLISHED)
                    ->where('status', '!=', Conversation::STATUS_SPAM)
                    ->where('subject', 'LIKE', '[dns:' . $catKey . ']%')
                    ->orderBy('last_reply_at', 'desc');
            }

            // Exclude domain conversations from standard folders
            $folderTypes = array_column(self::FOLDERS, 'type');
            if (!in_array($folder->type, $folderTypes)) {
                foreach (array_keys(self::FOLDERS) as $cat) {
                    $query = $query->where('subject', 'NOT LIKE', '[dns:' . $cat . ']%');
                }
            }

            return $query;
        }, 20, 3);

        \Eventy::addFilter('folder.update_counters', function ($updated, $folder) {
            $catKey = $this->folderTypeToCategory($folder->type);
            if ($catKey === null) {
                return $updated;
            }

            $folder->active_count = Conversation::where('mailbox_id', $folder->mailbox_id)
                ->where('state', Conversation::STATE_PUBLISHED)
                ->whereIn('status', [Conversation::STATUS_ACTIVE, Conversation::STATUS_PENDING])
                ->where('subject', 'LIKE', '[dns:' . $catKey . ']%')
                ->count();

            $folder->total_count = Conversation::where('mailbox_id', $folder->mailbox_id)
                ->where('state', Conversation::STATE_PUBLISHED)
                ->where('status', '!=', Conversation::STATUS_SPAM)
                ->where('subject', 'LIKE', '[dns:' . $catKey . ']%')
                ->count();

            $folder->save();
            return true;
        }, 20, 2);
    }

    protected function ensureDomainsFolders()
    {
        try {
            $mailboxes = \App\Mailbox::all();
            foreach ($mailboxes as $mailbox) {
                foreach (self::FOLDERS as $info) {
                    $exists = \App\Folder::where('mailbox_id', $mailbox->id)
                        ->where('type', $info['type'])
                        ->exists();
                    if (!$exists) {
                        $folder = new \App\Folder();
                        $folder->mailbox_id = $mailbox->id;
                        $folder->type = $info['type'];
                        $folder->save();
                    }
                }
            }
        } catch (\Exception $e) {
            // Table might not exist during install/migration
        }
    }

    /**
     * Resolve category from event, message_type, and order_reg_type.
     */
    protected function resolveCategory($event, $messageType, $orderRegType = '', $object = '')
    {
        // message_type is most specific (from MESSAGE_STATUS_CHANGE events)
        if (!empty($messageType) && isset(self::MESSAGE_TYPES[$messageType])) {
            return self::MESSAGE_TYPES[$messageType]['cat'];
        }
        if (!empty($messageType)) {
            if (stripos($messageType, 'renewal') !== false || stripos($messageType, 'renew') !== false) return 'renew';
            if (stripos($messageType, 'transfer') !== false || stripos($messageType, 'trade') !== false) return 'transfer';
            if (stripos($messageType, 'registration') !== false) return 'register';
            if (stripos($messageType, 'expir') !== false) return 'expire';
            if (stripos($messageType, 'delet') !== false) return 'delete';
            if (stripos($messageType, 'wdrp') !== false || stripos($messageType, 'verification') !== false) return 'wdrp';
        }
        // ORDER events: use order_reg_type
        if ($object === 'ORDER' && !empty($orderRegType) && isset(self::ORDER_REG_TYPES[$orderRegType])) {
            return self::ORDER_REG_TYPES[$orderRegType]['cat'];
        }
        // Fall back to event type
        if (!empty($event) && isset(self::EVENT_TYPES[$event])) {
            return self::EVENT_TYPES[$event]['cat'];
        }
        return 'other';
    }

    /**
     * Resolve human-readable label.
     */
    protected function resolveLabel($event, $messageType, $orderRegType = '', $orderStatus = '', $object = '')
    {
        if (!empty($messageType) && isset(self::MESSAGE_TYPES[$messageType])) {
            return self::MESSAGE_TYPES[$messageType]['label'];
        }
        if (!empty($messageType)) {
            return ucfirst(str_replace(['.', '_'], ' ', $messageType));
        }
        // ORDER events: combine reg_type + status
        if ($object === 'ORDER' && !empty($orderRegType)) {
            $regLabel = isset(self::ORDER_REG_TYPES[$orderRegType])
                ? self::ORDER_REG_TYPES[$orderRegType]['label']
                : ucfirst(str_replace('_', ' ', $orderRegType)) . ' order';
            if (!empty($orderStatus)) {
                $regLabel .= ' (' . $orderStatus . ')';
            }
            return $regLabel;
        }
        if (!empty($event) && isset(self::EVENT_TYPES[$event])) {
            return self::EVENT_TYPES[$event]['label'];
        }
        if (!empty($event)) {
            return ucfirst(strtolower(str_replace('_', ' ', $event)));
        }
        return 'Domain event';
    }

    protected function folderTypeToCategory($type)
    {
        foreach (self::FOLDERS as $catKey => $info) {
            if ($info['type'] == $type) {
                return $catKey;
            }
        }
        return null;
    }

    /**
     * Fallback: try to find OpenSRS JSON in the email body.
     */
    protected function extractJsonFromBody($body)
    {
        if (empty($body)) {
            return null;
        }
        $plain = strip_tags($body);
        $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = trim($plain);

        $json = json_decode($plain, true);
        if ($json && isset($json['event']) && isset($json['object_data'])) {
            return $json;
        }
        return null;
    }

    /**
     * Build readable HTML body from the JSON event.
     */
    protected function buildReadableBody($json)
    {
        $objectData = $json['object_data'] ?? [];
        $domain = $objectData['domain_name'] ?? 'unknown';
        $event = $json['event'] ?? '';
        $object = $json['object'] ?? '';
        $eventDate = $json['event_date'] ?? '';
        $messageType = $objectData['message_type'] ?? '';
        $messageStatus = $objectData['message_status'] ?? '';
        $toAddress = $objectData['to_address'] ?? '';
        $domainId = $objectData['domain_id'] ?? '';
        $orderRegType = $objectData['order_reg_type'] ?? '';
        $orderStatus = $objectData['order_status'] ?? '';
        $orderId = $objectData['order_id'] ?? '';
        $period = $objectData['period'] ?? '';
        $expirationDate = $objectData['expiration_date'] ?? '';

        $lines = [];
        $lines[] = '<div class="opensrs-event-summary">';
        $lines[] = '<b>Domain:</b> ' . htmlspecialchars($domain);
        $lines[] = '<b>Event:</b> ' . htmlspecialchars($object . ' ' . $event);
        if (!empty($messageType)) {
            $typeLabel = isset(self::MESSAGE_TYPES[$messageType]) ? self::MESSAGE_TYPES[$messageType]['label'] : $messageType;
            $lines[] = '<b>Type:</b> ' . htmlspecialchars($typeLabel);
        }
        if (!empty($orderRegType)) {
            $lines[] = '<b>Order type:</b> ' . htmlspecialchars(str_replace('_', ' ', $orderRegType));
        }
        if (!empty($orderStatus)) {
            $lines[] = '<b>Order status:</b> ' . htmlspecialchars($orderStatus);
        }
        if (!empty($messageStatus)) {
            $lines[] = '<b>Message status:</b> ' . htmlspecialchars($messageStatus);
        }
        if (!empty($toAddress)) {
            $lines[] = '<b>Sent to:</b> ' . htmlspecialchars($toAddress);
        }
        if (!empty($expirationDate)) {
            $lines[] = '<b>Expires:</b> ' . htmlspecialchars($expirationDate);
        }
        if (!empty($period)) {
            $lines[] = '<b>Period:</b> ' . htmlspecialchars($period) . ' year(s)';
        }
        if (!empty($eventDate)) {
            $lines[] = '<b>Date:</b> ' . htmlspecialchars($eventDate);
        }
        if (!empty($orderId)) {
            $lines[] = '<b>Order ID:</b> ' . htmlspecialchars($orderId);
        }
        if (!empty($domainId)) {
            $lines[] = '<b>Domain ID:</b> ' . htmlspecialchars($domainId);
        }
        $lines[] = '</div>';

        return implode('<br>', $lines);
    }

    /**
     * Find existing conversation for a domain + category to thread into.
     */
    protected function findExistingDomainThread($domain, $category)
    {
        $conversation = Conversation::where('subject', 'LIKE', '[dns:' . $category . '] ' . $domain . '%')
            ->where('state', Conversation::STATE_PUBLISHED)
            ->where('status', '!=', Conversation::STATUS_SPAM)
            ->orderBy('last_reply_at', 'desc')
            ->first();

        if (!$conversation) {
            return null;
        }

        return $conversation->threads()
            ->orderBy('created_at', 'desc')
            ->first();
    }
}
