<?php
declare(strict_types=1);

/**
 * MessageService.php — মেসেজিং সিস্টেমের কোর বিজনেস লজিক
 *
 * কী করে:
 *   • স্টাফ খুঁজে বের করে (ইউজার আইডি / ইমেইল / ফোন দিয়ে)
 *   • থ্রেড ও মেসেজ তৈরি
 *   • ব্যবহারকারীর থ্রেড লিস্ট আনে (লগইন করা/না করা — উভয় ক্ষেত্রে)
 *   • প্রি-লগইন মেসেজ অটো-লিংক করে ব্যবহারকারীর অ্যাকাউন্টে
 *   • অ্যাডমিন প্যানেলের জন্য সব থ্রেড আনে
 *   • রিপ্লাই পাঠায়
 *   • পারমিশন চেক (একজন অন্যের মেসেজ দেখতে পারবে না)
 */
class MessageService
{
    private PDO $conn;

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
    }

    /* ── ১. স্টাফ সার্চ ─────────────────────────────────────── */

    /**
     * ইউজার আইডি, ইমেইল বা ফোন দিয়ে স্টাফ/অ্যাডমিন খুঁজে বের করে
     */
    public function findStaff(string $identifier): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT id, username AS name, email, phone, role
            FROM users
            WHERE (id = :id OR email = :email OR phone = :phone)
              AND role IN ('admin', 'staff', 'manager', 'moderator')
            LIMIT 1
        ");
        $stmt->execute([
            ':id'    => is_numeric($identifier) ? (int)$identifier : 0,
            ':email' => $identifier,
            ':phone' => $identifier,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /* ── ২. নতুন মেসেজ/থ্রেড তৈরি ──────────────────────────── */

    /**
     * নতুন থ্রেড তৈরি করে প্রথম মেসেজ সেভ করে
     *
     * @param array $data {
     *   name, email, phone, identifier, message, attachment,
     *   user_id, ip
     * }
     * @return int thread_id
     */
    public function createThread(array $data): int
    {
        // স্টাফ খুঁজে বের করো (যদি কোনো আইডেন্টিফায়ার দেওয়া থাকে)
        $staff = null;
        if (!empty($data['identifier'])) {
            $staff = $this->findStaff($data['identifier']);
        }

        $this->conn->beginTransaction();
        try {
            // থ্রেড তৈরি
            $stmt = $this->conn->prepare("
                INSERT INTO message_threads
                    (visitor_email, visitor_phone, linked_user_id,
                     assigned_staff_id, subject, status, last_message_at)
                VALUES (?, ?, ?, ?, ?, 'open', NOW())
            ");
            $stmt->execute([
                $data['email']   ?? '',
                $data['phone']   ?? '',
                $data['user_id'] ?? null,
                $staff['id']     ?? null,
                $data['subject'] ?? null,
            ]);
            $threadId = (int)$this->conn->lastInsertId();

            // মেসেজ সেভ
            $stmt = $this->conn->prepare("
                INSERT INTO messages
                    (thread_id, sender_type, sender_user_id, sender_name,
                     sender_email, sender_phone, recipient_user_id,
                     message, attachment, ip_address)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $threadId,
                !empty($data['user_id']) ? 'registered' : 'visitor',
                $data['user_id'] ?? null,
                $data['name']    ?? 'অজানা',
                $data['email']   ?? '',
                $data['phone']   ?? '',
                $staff['id']     ?? null,
                $data['message'],
                $data['attachment'] ?? null,
                $data['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? null),
            ]);

            $this->conn->commit();
            return $threadId;
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            throw $e;
        }
    }

    /* ── ৩. ব্যবহারকারীর থ্রেড লিস্ট ───────────────────────── */

    /**
     * ব্যবহারকারীর সব থ্রেড আনে।
     * লগইন করা থাকলে user_id + email + phone দিয়ে খোঁজে এবং
     * অটো-লিংক করে (প্রি-লগইন মেসেজগুলোকে অ্যাকাউন্টে যুক্ত করে)।
     */
    public function getThreads(?int $userId, ?string $email, ?string $phone): array
    {
        if ($userId) {
            $this->linkThreadsToUser($userId, $email, $phone);

            $stmt = $this->conn->prepare("
                SELECT
                    t.*,
                    u.username AS linked_user_name,
                    s.username AS assigned_staff_name,
                    s.email    AS assigned_staff_email,
                    s.phone    AS assigned_staff_phone,
                    (SELECT COUNT(*) FROM messages
                     WHERE thread_id = t.id AND sender_type != 'admin' AND is_read = 0
                    ) AS unread_count,
                    (SELECT message FROM messages
                     WHERE thread_id = t.id ORDER BY created_at DESC LIMIT 1
                    ) AS last_message,
                    (SELECT created_at FROM messages
                     WHERE thread_id = t.id ORDER BY created_at DESC LIMIT 1
                    ) AS last_message_time
                FROM message_threads t
                LEFT JOIN users u ON t.linked_user_id = u.id
                LEFT JOIN users s ON t.assigned_staff_id = s.id
                WHERE t.linked_user_id = :uid
                   OR t.visitor_email   = :email
                   OR t.visitor_phone   = :phone
                ORDER BY t.last_message_at DESC
            ");
            $stmt->execute([':uid' => $userId, ':email' => $email, ':phone' => $phone]);
        } else {
            // লগইন না করা — শুধু email+phone দিয়ে
            if (empty($email) || empty($phone)) {
                return [];
            }
            $stmt = $this->conn->prepare("
                SELECT
                    t.*,
                    u.username AS linked_user_name,
                    s.username AS assigned_staff_name,
                    s.email    AS assigned_staff_email,
                    s.phone    AS assigned_staff_phone,
                    (SELECT COUNT(*) FROM messages
                     WHERE thread_id = t.id AND sender_type != 'admin' AND is_read = 0
                    ) AS unread_count,
                    (SELECT message FROM messages
                     WHERE thread_id = t.id ORDER BY created_at DESC LIMIT 1
                    ) AS last_message,
                    (SELECT created_at FROM messages
                     WHERE thread_id = t.id ORDER BY created_at DESC LIMIT 1
                    ) AS last_message_time
                FROM message_threads t
                LEFT JOIN users u ON t.linked_user_id = u.id
                LEFT JOIN users s ON t.assigned_staff_id = s.id
                WHERE t.visitor_email = :email AND t.visitor_phone = :phone
                ORDER BY t.last_message_at DESC
            ");
            $stmt->execute([':email' => $email, ':phone' => $phone]);
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ── ৪. থ্রেডের মেসেজগুলো আনা ──────────────────────────── */

    /**
     * নির্দিষ্ট থ্রেডের সব মেসেজ আনে (পারমিশন চেক সহ)।
     *
     * @throws Exception যদি অনুমতি না থাকে
     */
    public function getThreadMessages(
        int $threadId,
        ?int $viewerUserId,
        ?string $viewerEmail,
        ?string $viewerPhone,
        bool $isAdmin = false
    ): array {
        // পারমিশন চেক
        $stmt = $this->conn->prepare("
            SELECT id FROM message_threads
            WHERE id = :tid
              AND (linked_user_id = :uid
                   OR visitor_email = :email
                   OR visitor_phone = :phone)
            LIMIT 1
        ");
        $stmt->execute([
            ':tid'   => $threadId,
            ':uid'   => $viewerUserId,
            ':email' => $viewerEmail,
            ':phone' => $viewerPhone,
        ]);
        $owns = (bool)$stmt->fetch();

        if (!$owns && !$isAdmin) {
            throw new Exception('আপনার এই কনভারসেশন দেখার অনুমতি নেই।');
        }

        // মেসেজ আনো
        $stmt = $this->conn->prepare("
            SELECT
                m.*,
                u.username AS sender_username,
                u.role     AS sender_role
            FROM messages m
            LEFT JOIN users u ON m.sender_user_id = u.id
            WHERE m.thread_id = :tid
            ORDER BY m.created_at ASC
        ");
        $stmt->execute([':tid' => $threadId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ── ৫. অ্যাডমিন: সব থ্রেড ─────────────────────────────── */

    public function getAllThreads(): array
    {
        $stmt = $this->conn->query("
            SELECT
                t.*,
                u.username AS linked_user_name,
                s.username AS assigned_staff_name,
                s.email    AS assigned_staff_email,
                s.phone    AS assigned_staff_phone,
                (SELECT COUNT(*) FROM messages
                 WHERE thread_id = t.id AND sender_type != 'admin' AND is_read = 0
                ) AS unread_count,
                (SELECT message FROM messages
                 WHERE thread_id = t.id ORDER BY created_at DESC LIMIT 1
                ) AS last_message,
                (SELECT created_at FROM messages
                 WHERE thread_id = t.id ORDER BY created_at DESC LIMIT 1
                ) AS last_message_time
            FROM message_threads t
            LEFT JOIN users u ON t.linked_user_id = u.id
            LEFT JOIN users s ON t.assigned_staff_id = s.id
            ORDER BY t.last_message_at DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ── ৬. রিপ্লাই পাঠানো ─────────────────────────────────── */

    /**
     * অ্যাডমিন/স্টাফ থেকে রিপ্লাই পাঠায়
     */
    public function reply(int $threadId, int $adminUserId, string $message, ?string $attachment): void
    {
        $this->conn->beginTransaction();
        try {
            // অ্যাডমিনের তথ্য
            $adm = $this->conn->prepare("SELECT username, email, phone FROM users WHERE id = ? LIMIT 1");
            $adm->execute([$adminUserId]);
            $admin = $adm->fetch(PDO::FETCH_ASSOC);
            if (!$admin) {
                throw new Exception('প্রেরকের তথ্য পাওয়া যায়নি।');
            }

            $stmt = $this->conn->prepare("
                INSERT INTO messages
                    (thread_id, sender_type, sender_user_id, sender_name,
                     sender_email, sender_phone, message, attachment)
                VALUES (?, 'admin', ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $threadId,
                $adminUserId,
                $admin['username'],
                $admin['email']   ?? '',
                $admin['phone']   ?? '',
                $message,
                $attachment,
            ]);

            $this->conn->prepare("
                UPDATE message_threads
                SET status = 'replied', last_message_at = NOW(), updated_at = NOW()
                WHERE id = ?
            ")->execute([$threadId]);

            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            throw $e;
        }
    }

    /* ── ৭. পঠিত চিহ্নিত করা ──────────────────────────────── */

    public function markAsRead(int $threadId, string $readerType): void
    {
        $stmt = $this->conn->prepare("
            UPDATE messages
            SET is_read = 1
            WHERE thread_id = ? AND sender_type != ?
        ");
        $stmt->execute([$threadId, $readerType]);
    }

    /* ── ৮. থ্রেড অ্যাকাউন্টে লিংক করা (প্রাইভেট) ──────────── */

    private function linkThreadsToUser(int $userId, ?string $email, ?string $phone): void
    {
        if (empty($email) && empty($phone)) {
            return;
        }
        $parts = [];
        $params = [$userId];
        if ($email) {
            $parts[] = 'visitor_email = ?';
            $params[] = $email;
        }
        if ($phone) {
            $parts[] = 'visitor_phone = ?';
            $params[] = $phone;
        }
        $sql = "UPDATE message_threads SET linked_user_id = ? 
                WHERE linked_user_id IS NULL AND (" . implode(' OR ', $parts) . ")";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
    }
}
