<?php

namespace Modules\OpenSrsEvents\Providers;

use Illuminate\Support\ServiceProvider;
use App\Conversation;

define('OPENSRSEVENTS_MODULE', 'opensrsevents');

class OpenSrsEventsServiceProvider extends ServiceProvider
{
    // Folder types — one per domain event category
    const FOLDERS = [
        'renew'    => ['type' => 121, 'name' => 'Domains / Renewals',    'icon' => 'refresh'],
        'transfer' => ['type' => 122, 'name' => 'Domains / Transfers',   'icon' => 'transfer'],
        'register' => ['type' => 123, 'name' => 'Domains / Registrations', 'icon' => 'plus-sign'],
        'expire'   => ['type' => 124, 'name' => 'Domains / Expiry',      'icon' => 'time'],
        'delete'   => ['type' => 125, 'name' => 'Domains / Deletions',   'icon' => 'trash'],
        'wdrp'     => ['type' => 126, 'name' => 'Domains / WDRP',        'icon' => 'envelope'],
        'other'    => ['type' => 127, 'name' => 'Domains / Other',       'icon' => 'globe'],
    ];

    // Map message_type to category + readable label
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

    // Map event types to category + readable label (fallback when no message_type)
    const EVENT_TYPES = [
        'REGISTERED'                              => ['cat' => 'register', 'label' => 'Registered'],
        'RENEWED'                                 => ['cat' => 'renew',    'label' => 'Renewed'],
        'EXPIRED'                                 => ['cat' => 'expire',   'label' => 'Expired'],
        'DELETED'                                 => ['cat' => 'delete',   'label' => 'Deleted'],
        'STATUS_CHANGE'                           => ['cat' => 'other',    'label' => 'Status change'],
        'NAMESERVER_UPDATE'                       => ['cat' => 'other',    'label' => 'Nameserver update'],
        'ZONE_CHECK_STATUS_CHANGE'                => ['cat' => 'other',    'label' => 'Zone check status change'],
        'CLAIM_STATUS_CHANGE'                     => ['cat' => 'other',    'label' => 'Claim status change'],
        'REGISTRANT_VERIFICATION_STATUS_CHANGE'   => ['cat' => 'wdrp',     'label' => 'Registrant verification status change'],
        'ICANN_TRADE_STATUS_CHANGE'               => ['cat' => 'transfer', 'label' => 'Registrant change status'],
        'MESSAGE_STATUS_CHANGE'                   => ['cat' => 'other',    'label' => 'Message status change'],
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
            $body = $data['body'] ?? '';

            $json = $this->extractOpenSrsJson($body);
            if (!$json) {
                return $data;
            }

            $objectData = $json['object_data'] ?? [];
            $domain = $objectData['domain_name'] ?? 'unknown';
            $event = $json['event'] ?? '';
            $messageType = $objectData['message_type'] ?? '';

            // Determine category
            $category = $this->resolveCategory($event, $messageType);
            $label = $this->resolveLabel($event, $messageType);

            // Subject: [dns:renew] example.com — Renewal confirmation
            $data['subject'] = '[dns:' . $category . '] ' . $domain . ' — ' . $label;

            // Replace raw JSON with readable body
            $data['body'] = $this->buildReadableBody($json);

            // Prevent auto-replies
            $data['auto_reply_sent'] = true;

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

        // Register all domain folder types as public
        \Eventy::addFilter('mailbox.folders.public_types', function ($list) use ($folderTypes) {
            return array_merge($list, $folderTypes);
        }, 20, 1);

        // Set folder names
        \Eventy::addFilter('folder.type_name', function ($name, $folder) {
            foreach (self::FOLDERS as $info) {
                if ($folder->type == $info['type']) {
                    return $info['name'];
                }
            }
            return $name;
        }, 20, 2);

        // Set folder icons
        \Eventy::addFilter('folder.type_icon', function ($icon, $folder) {
            foreach (self::FOLDERS as $info) {
                if ($folder->type == $info['type']) {
                    return $info['icon'];
                }
            }
            return $icon;
        }, 20, 2);

        // Query filter: route conversations to the right folder, exclude from standard folders
        \Eventy::addFilter('folder.conversations_query', function ($query, $folder, $user_id) {
            $catKey = $this->folderTypeToCategory($folder->type);

            if ($catKey !== null) {
                return Conversation::where('mailbox_id', $folder->mailbox_id)
                    ->where('state', Conversation::STATE_PUBLISHED)
                    ->where('status', '!=', Conversation::STATUS_SPAM)
                    ->where('subject', 'LIKE', '[dns:' . $catKey . ']%')
                    ->orderBy('last_reply_at', 'desc');
            }

            // For non-domain folders: exclude all domain conversations
            $folderTypes = array_column(self::FOLDERS, 'type');
            if (!in_array($folder->type, $folderTypes)) {
                foreach (array_keys(self::FOLDERS) as $cat) {
                    $query = $query->where('subject', 'NOT LIKE', '[dns:' . $cat . ']%');
                }
            }

            return $query;
        }, 20, 3);

        // Counter updates for each folder
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
     * Resolve the category key from event + message_type.
     */
    protected function resolveCategory($event, $messageType)
    {
        if (!empty($messageType) && isset(self::MESSAGE_TYPES[$messageType])) {
            return self::MESSAGE_TYPES[$messageType]['cat'];
        }
        // Infer from message_type keywords
        if (!empty($messageType)) {
            if (strpos($messageType, 'renewal') !== false || strpos($messageType, 'renew') !== false) return 'renew';
            if (strpos($messageType, 'transfer') !== false || strpos($messageType, 'trade') !== false) return 'transfer';
            if (strpos($messageType, 'registration') !== false) return 'register';
            if (strpos($messageType, 'expir') !== false) return 'expire';
            if (strpos($messageType, 'delet') !== false) return 'delete';
            if (strpos($messageType, 'wdrp') !== false || strpos($messageType, 'verification') !== false) return 'wdrp';
        }
        if (!empty($event) && isset(self::EVENT_TYPES[$event])) {
            return self::EVENT_TYPES[$event]['cat'];
        }
        return 'other';
    }

    /**
     * Resolve the human-readable label from event + message_type.
     */
    protected function resolveLabel($event, $messageType)
    {
        if (!empty($messageType) && isset(self::MESSAGE_TYPES[$messageType])) {
            return self::MESSAGE_TYPES[$messageType]['label'];
        }
        if (!empty($messageType)) {
            return ucfirst(str_replace(['.', '_'], ' ', $messageType));
        }
        if (!empty($event) && isset(self::EVENT_TYPES[$event])) {
            return self::EVENT_TYPES[$event]['label'];
        }
        if (!empty($event)) {
            return ucfirst(strtolower(str_replace('_', ' ', $event)));
        }
        return 'Domain event';
    }

    /**
     * Map a folder type ID back to a category key, or null if not ours.
     */
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
     * Extract OpenSRS JSON from an email body.
     */
    protected function extractOpenSrsJson($body)
    {
        if (empty($body)) {
            return null;
        }

        $plain = strip_tags($body);
        $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = trim($plain);

        // Try the whole body as JSON
        $json = json_decode($plain, true);
        if ($json && isset($json['event']) && isset($json['object_data'])) {
            return $json;
        }

        // Try to find JSON object within the text
        if (preg_match('/\{(?:[^{}]|(?:\{[^{}]*\}))*"event"\s*:(?:[^{}]|(?:\{[^{}]*\}))*\}/s', $plain, $matches)) {
            $json = json_decode($matches[0], true);
            if ($json && isset($json['event']) && isset($json['object_data'])) {
                return $json;
            }
        }

        return null;
    }

    /**
     * Build a clean HTML body from the JSON event.
     */
    protected function buildReadableBody($json)
    {
        $objectData = $json['object_data'] ?? [];
        $domain = $objectData['domain_name'] ?? 'unknown';
        $event = $json['event'] ?? '';
        $eventDate = $json['event_date'] ?? '';
        $messageType = $objectData['message_type'] ?? '';
        $messageStatus = $objectData['message_status'] ?? '';
        $toAddress = $objectData['to_address'] ?? '';
        $domainId = $objectData['domain_id'] ?? '';

        $eventLabel = isset(self::EVENT_TYPES[$event]) ? self::EVENT_TYPES[$event]['label'] : ucfirst(strtolower(str_replace('_', ' ', $event)));
        $typeLabel = '';
        if (!empty($messageType)) {
            $typeLabel = isset(self::MESSAGE_TYPES[$messageType]) ? self::MESSAGE_TYPES[$messageType]['label'] : str_replace(['.', '_'], ' ', $messageType);
        }

        $lines = [];
        $lines[] = '<div class="opensrs-event-summary">';
        $lines[] = '<b>Domain:</b> ' . htmlspecialchars($domain);
        $lines[] = '<b>Event:</b> ' . htmlspecialchars($eventLabel);
        if (!empty($typeLabel)) {
            $lines[] = '<b>Type:</b> ' . htmlspecialchars($typeLabel);
        }
        if (!empty($messageStatus)) {
            $lines[] = '<b>Status:</b> ' . htmlspecialchars($messageStatus);
        }
        if (!empty($toAddress)) {
            $lines[] = '<b>Sent to:</b> ' . htmlspecialchars($toAddress);
        }
        if (!empty($eventDate)) {
            $lines[] = '<b>Date:</b> ' . htmlspecialchars($eventDate);
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
