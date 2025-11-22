<?php
// api/invoice_helper.php

require_once 'db.php';

function createInvoiceForJobOrder(PDO $pdo, int $jobOrderId, int $customerId, float $totalAmount, string $jobDetails): ?int {
    try {
        $pdo->beginTransaction();

        // 1. Create the main invoice record
        $invoiceNumber = 'INV-' . time() . rand(100, 999);
        $today = date("Y-m-d");
        $dueDate = date("Y-m-d", strtotime("+30 days")); // Due in 30 days

        $stmt = $pdo->prepare(
            "INSERT INTO invoices (customer_id, invoice_number, issue_date, due_date, total_amount, status) 
             VALUES (:customer_id, :invoice_number, :issue_date, :due_date, :total_amount, 'Unpaid')"
        );

        $stmt->bindParam(':customer_id', $customerId);
        $stmt->bindParam(':invoice_number', $invoiceNumber);
        $stmt->bindParam(':issue_date', $today);
        $stmt->bindParam(':due_date', $dueDate);
        $stmt->bindParam(':total_amount', $totalAmount);
        $stmt->execute();
        
        $invoiceId = $pdo->lastInsertId();

        // 2. Create the invoice item
        $stmt = $pdo->prepare(
            "INSERT INTO invoice_items (invoice_id, description, quantity, unit_price) 
             VALUES (:invoice_id, :description, 1, :unit_price)"
        );

        $stmt->bindParam(':invoice_id', $invoiceId);
        $stmt->bindParam(':description', $jobDetails);
        $stmt->bindParam(':unit_price', $totalAmount);
        $stmt->execute();

        $pdo->commit();

        return $invoiceId;

    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Failed to create invoice for job order #$jobOrderId: " . $e->getMessage());
        return null;
    }
}
