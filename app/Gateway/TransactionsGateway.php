<?php

namespace App\Gateway;

use Illuminate\Database\ConnectionInterface;

class TransactionsGateway
{
    /**
     * @var ConnectionInterface
     */
    private $db;

    public function __construct()
    {
        $this->db = app('db');
    }

    public function changeMode(int $mode, string $userId)
    {
        $this->db->table("users")->update([
            "transaction_mode" => $mode,
            "user_id" => $userId
        ]);
    }


    public function saveTransaction(int $nominal, int $type, int $userId)
    {
        $this->db->table('transactions')
            ->insert([
                'nominal' => $nominal,
                'type' => $type,
                'user_id' => $userId
            ]);
    }
}
