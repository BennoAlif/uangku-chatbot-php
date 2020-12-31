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


    public function saveTransaction(int $nominal, int $type, int $userId)
    {
        $this->db->table('transactions')
            ->insert([
                'nominal' => $nominal,
                'type' => $type,
                'user_id' => $userId
            ]);
    }

    public function getIncome(string $userId)
    {
        $income = $this->db->table('transactions')
            ->where('user_id', $userId)
            ->where('type', 0)
            ->sum("nominal");

        if ($income) {
            return (array) $income;
        }

        return null;
    }

    public function getExpense(string $userId)
    {
        $expense = $this->db->table('transactions')
            ->where('user_id', $userId)
            ->where('type', 1)
            ->sum("nominal");

        if ($expense) {
            return (array) $expense;
        }

        return null;
    }
}
