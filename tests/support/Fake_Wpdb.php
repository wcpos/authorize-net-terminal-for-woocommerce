<?php

/**
 * Just enough $wpdb for Claim_Store: a wp_options table with a unique option_name.
 */
final class Antwc_Fake_Wpdb
{
    /** @var string */
    public $options = 'wp_options';
    /** @var array<string, string> option_name => option_value */
    public $rows = array();
    /** @var callable|null Runs once, just before the next INSERT lands; used to stage races. */
    public $before_insert = null;
    /** @var bool The next query() returns false. */
    public $fail_next_query = false;
    /** @var int */
    public $inserts = 0;

    /**
     * @param mixed ...$args
     */
    public function prepare(string $query, ...$args): string
    {
        return (string) json_encode(array($query, $args));
    }

    /**
     * @return int|false
     */
    public function query(string $prepared)
    {
        if ($this->fail_next_query) {
            $this->fail_next_query = false;

            return false;
        }
        list($query, $args) = json_decode($prepared, true);
        if (strpos($query, 'INSERT IGNORE INTO wp_options') !== 0) {
            throw new RuntimeException('Fake_Wpdb: unexpected query ' . $query);
        }
        if ($this->before_insert !== null) {
            $hook = $this->before_insert;
            $this->before_insert = null;
            $hook();
        }
        $this->inserts++;
        if (array_key_exists($args[0], $this->rows)) {
            return 0;
        }
        $this->rows[$args[0]] = $args[1];

        return 1;
    }

    /**
     * @return string|null
     */
    public function get_var(string $prepared)
    {
        list($query, $args) = json_decode($prepared, true);
        if (strpos($query, 'SELECT option_value FROM wp_options WHERE option_name = %s') !== 0) {
            throw new RuntimeException('Fake_Wpdb: unexpected select ' . $query);
        }

        return $this->rows[$args[0]] ?? null;
    }
}
