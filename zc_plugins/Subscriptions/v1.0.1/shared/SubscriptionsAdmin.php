<?php
/**
 * Subscriptions -- what the admin page reads: counts by status, the searched
 * and paged list, and one subscription in full.
 *
 * Changes go through SubscriptionsManager (with an admin actor), so the admin
 * and the customer obey the same rules.
 *
 * @package  Subscriptions
 * @license  http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

if (!class_exists('SubscriptionsCore', false)) {
    require_once __DIR__ . '/SubscriptionsCore.php';
}

class SubscriptionsAdmin
{
    public const STATUSES = ['active', 'paused', 'past_due', 'canceled', 'expired'];

    public const PER_PAGE = 50;

    /** The most customers a name or email search matches before it stops looking. */
    public const SEARCH_LIMIT = 200;

    /** @var object queryFactory */
    protected $db;

    public function __construct($db)
    {
        SubscriptionsCore::defineTables();
        $this->db = $db;
    }

    /** @return array<string, int> status => number of subscriptions, every status present */
    public function counts(): array
    {
        $out = array_fill_keys(self::STATUSES, 0);
        $r = $this->db->Execute("SELECT status, COUNT(*) AS n FROM " . TABLE_SUBSCRIPTIONS . " GROUP BY status");
        while (!$r->EOF) {
            $out[(string)$r->fields['status']] = (int)$r->fields['n'];
            $r->MoveNext();
        }
        return $out;
    }

    /**
     * One page of subscriptions, newest first.
     *
     * @param string $status one of STATUSES, anything else means all
     * @param string $query  a subscription or order number, or part of a customer's name or email
     * @return array{rows: array, total: int, page: int, pages: int, status: string, query: string}
     */
    public function search(string $status, string $query, int $page): array
    {
        $status = in_array($status, self::STATUSES, true) ? $status : '';
        $query = trim(preg_replace('/\s+/', ' ', $query));
        $where = [];
        if ($status !== '') {
            $where[] = "status = '" . $this->db->prepare_input($status) . "'";
        }
        if ($query !== '') {
            if (preg_match('/^#?(\d{1,10})$/', $query, $m)) {
                $n = (int)$m[1];
                $where[] = "(subscriptions_id = " . $n . " OR origin_orders_id = " . $n . " OR last_orders_id = " . $n . ")";
            } else {
                $ids = $this->customerIds($query);
                if ($ids === []) {
                    return ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'status' => $status, 'query' => $query];
                }
                $where[] = "customers_id IN (" . implode(', ', $ids) . ")";
            }
        }
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $t = $this->db->Execute("SELECT COUNT(*) AS n FROM " . TABLE_SUBSCRIPTIONS . $whereSql);
        $total = $t->EOF ? 0 : (int)$t->fields['n'];
        $pages = max(1, (int)ceil($total / self::PER_PAGE));
        $page = max(1, min($page, $pages));

        $rows = [];
        $r = $this->db->Execute(
            "SELECT * FROM " . TABLE_SUBSCRIPTIONS . $whereSql . " ORDER BY subscriptions_id DESC LIMIT " . (($page - 1) * self::PER_PAGE) . ", " . self::PER_PAGE
        );
        while (!$r->EOF) {
            $rows[(int)$r->fields['subscriptions_id']] = $r->fields + ['customer' => null, 'items' => []];
            $r->MoveNext();
        }
        if ($rows !== []) {
            $customers = $this->customers(array_unique(array_map('intval', array_column($rows, 'customers_id'))));
            foreach ($rows as $id => $row) {
                $rows[$id]['customer'] = $customers[(int)$row['customers_id']] ?? null;
            }
            $p = $this->db->Execute(
                "SELECT subscriptions_id, products_id, products_name, quantity FROM " . TABLE_SUBSCRIPTIONS_PRODUCTS
                . " WHERE subscriptions_id IN (" . implode(', ', array_keys($rows)) . ") ORDER BY subscriptions_products_id"
            );
            while (!$p->EOF) {
                $rows[(int)$p->fields['subscriptions_id']]['items'][] = $p->fields;
                $p->MoveNext();
            }
        }
        return ['rows' => array_values($rows), 'total' => $total, 'page' => $page, 'pages' => $pages, 'status' => $status, 'query' => $query];
    }

    /** One subscription with its customer, items and history (newest first); null if there's no such subscription. */
    public function detail(int $subId): ?array
    {
        if ($subId <= 0) {
            return null;
        }
        $r = $this->db->Execute("SELECT * FROM " . TABLE_SUBSCRIPTIONS . " WHERE subscriptions_id = " . $subId . " LIMIT 1");
        if ($r->EOF) {
            return null;
        }
        $s = $r->fields;
        $s['customer'] = $this->customers([(int)$s['customers_id']])[(int)$s['customers_id']] ?? null;
        $s['items'] = [];
        $p = $this->db->Execute("SELECT * FROM " . TABLE_SUBSCRIPTIONS_PRODUCTS . " WHERE subscriptions_id = " . $subId . " ORDER BY subscriptions_products_id");
        while (!$p->EOF) {
            $s['items'][] = $p->fields;
            $p->MoveNext();
        }
        $s['history'] = [];
        $h = $this->db->Execute("SELECT * FROM " . TABLE_SUBSCRIPTIONS_HISTORY . " WHERE subscriptions_id = " . $subId . " ORDER BY subscriptions_history_id DESC");
        while (!$h->EOF) {
            $s['history'][] = $h->fields;
            $h->MoveNext();
        }
        return $s;
    }

    /** @return int[] customers whose name or email contains $text */
    protected function customerIds(string $text): array
    {
        // LIKE wildcards in what the admin typed are literal.
        $like = "'%" . $this->db->prepare_input(addcslashes($text, '%_')) . "%'";
        $conds = ["customers_email_address LIKE " . $like, "customers_firstname LIKE " . $like, "customers_lastname LIKE " . $like];
        $words = explode(' ', $text, 2);
        if (count($words) === 2) {
            // "Pat Lee": first and last name.
            $conds[] = "(customers_firstname LIKE '%" . $this->db->prepare_input(addcslashes($words[0], '%_')) . "%' AND customers_lastname LIKE '%"
                . $this->db->prepare_input(addcslashes($words[1], '%_')) . "%')";
        }
        $ids = [];
        $r = $this->db->Execute("SELECT customers_id FROM " . TABLE_CUSTOMERS . " WHERE (" . implode(' OR ', $conds) . ") LIMIT " . self::SEARCH_LIMIT);
        while (!$r->EOF) {
            $ids[] = (int)$r->fields['customers_id'];
            $r->MoveNext();
        }
        return $ids;
    }

    /** @return array<int, array{id:int, name:string, email:string}> */
    protected function customers(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        $out = [];
        if ($ids === []) {
            return $out;
        }
        $r = $this->db->Execute(
            "SELECT customers_id, customers_firstname, customers_lastname, customers_email_address FROM " . TABLE_CUSTOMERS
            . " WHERE customers_id IN (" . implode(', ', $ids) . ")"
        );
        while (!$r->EOF) {
            $out[(int)$r->fields['customers_id']] = [
                'id' => (int)$r->fields['customers_id'],
                'name' => trim($r->fields['customers_firstname'] . ' ' . $r->fields['customers_lastname']),
                'email' => (string)$r->fields['customers_email_address'],
            ];
            $r->MoveNext();
        }
        return $out;
    }

    /** How long since the scheduler ran: [Y-m-d H:i:s or '', hours since or null, healthy?]. */
    public static function schedulerHealth(string $lastRun, int $now): array
    {
        $t = $lastRun !== '' ? strtotime($lastRun) : false;
        if ($t === false) {
            return ['', null, false];
        }
        $hours = max(0, intdiv($now - $t, 3600));
        // Every 6 hours is the recommended cron, daily the least that works: past 25 hours it has stopped.
        return [$lastRun, $hours, $hours < 25];
    }
}
