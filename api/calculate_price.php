<?php
// api/calculate_price.php

function calculatePrintingPrice($size, $material, $copies, $finishingOptions = []) {
    global $pdo; // Use the global PDO object from db.php

    // Fetch base costs from the database
    // This is a simplified model. A real system would have more complex lookups.
    $costs = [];
    $stmt = $pdo->query("SELECT item_name, price FROM material_costs");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Normalize item names to be used as keys (e.g., 'Artpaper 300gsm' -> 'artpaper_300gsm')
        $key = strtolower(str_replace(' ', '_', $row['item_name']));
        $costs[$key] = (float)$row['price'];
    }

    // --- Basic Cost Calculation Logic ---
    // This logic is highly dependent on your business model.
    // You would expand this with real-world formulas.

    $cost_per_sheet = 0;
    // Example: Look up the cost of the selected material. Fallback to a default if not found.
    $material_key = strtolower(str_replace(' ', '_', $material));
    if (isset($costs[$material_key])) {
        $cost_per_sheet = $costs[$material_key];
    } else {
        $cost_per_sheet = 50.0; // Default cost if material not in DB
    }

    // Adjust cost based on size (e.g., A3 is twice the cost of A4)
    if ($size === 'A3') {
        $cost_per_sheet *= 2;
    }

    $totalMaterialCost = $cost_per_sheet * $copies;

    // Add finishing costs
    $finishingCost = 0;
    foreach ($finishingOptions as $option) {
        $option_key = strtolower(str_replace(' ', '_', $option));
        if (isset($costs[$option_key])) {
            $finishingCost += $costs[$option_key]; // Assume finishing cost is per job, not per copy
        }
    }
    $totalMaterialCost += $finishingCost;


    // --- Add Profit Margin ---
    // Fetch profit margin from settings (or use a default)
    // For now, we'll hardcode it. In a real app, this comes from a settings table.
    $profitMarginPercent = 50.0; // 50% profit margin

    $priceBeforeProfit = $totalMaterialCost;
    $profitAmount = $priceBeforeProfit * ($profitMarginPercent / 100);
    $finalPrice = $priceBeforeProfit + $profitAmount;

    // Return the final price, rounded to 2 decimal places
    return round($finalPrice, 2);
}
