<?php
require_once 'db.php';

try {
    // 1. Clear existing templates
    $pdo->exec("TRUNCATE TABLE workflow_templates");
    echo "Existing templates cleared.\n";

    // 2. Define the 3 New Templates
    $templates = [
        [
            'title' => 'Welcome & Assign',
            'description' => 'Greets new customers, asks a qualifying question, tags them, and assigns an agent.',
            'category' => 'Onboarding',
            'icon_class' => 'fas fa-hand-sparkles',
            'workflow_data' => json_encode([
                'trigger_type' => 'CONVERSATION_STARTED',
                'steps' => [
                    [
                        'action_type' => 'SEND_MESSAGE',
                        'content' => 'Jambo {{customer_name}}! Karibu ChatMe. Tunafurahi kuwasiliana nawe.',
                        'meta_data' => ['type' => 'text']
                    ],
                    [
                        'action_type' => 'DELAY',
                        'content' => '1', // 1 minute
                        'meta_data' => []
                    ],
                    [
                        'action_type' => 'ASK_QUESTION',
                        'content' => 'Je, unahitaji msaada gani leo?',
                        'meta_data' => ['options' => 'Mauzo, Msaada, Mengine']
                    ],
                    [
                        'action_type' => 'ADD_TAG',
                        'content' => 'New Lead',
                        'meta_data' => []
                    ],
                    [
                        'action_type' => 'ASSIGN_AGENT',
                        'content' => 'Round Robin',
                        'meta_data' => []
                    ]
                ]
            ])
        ],
        [
            'title' => 'Customer Feedback Survey',
            'description' => 'Automatically asks for feedback and ratings after a conversation is resolved.',
            'category' => 'Support',
            'icon_class' => 'fas fa-smile-beam',
            'workflow_data' => json_encode([
                'trigger_type' => 'CONVERSATION_CLOSED',
                'steps' => [
                    [
                        'action_type' => 'DELAY',
                        'content' => '5', // 5 minutes delay after closing
                        'meta_data' => []
                    ],
                    [
                        'action_type' => 'SEND_MESSAGE',
                        'content' => 'Asante kwa kuwasiliana nasi. Tunatumai umeridhika na huduma yetu leo.',
                        'meta_data' => ['type' => 'text']
                    ],
                    [
                        'action_type' => 'ASK_QUESTION',
                        'content' => 'Je, unaweza kutupatia nyota ngapi kwa huduma yetu? (1 = Mbaya, 5 = Nzuri Sana)',
                        'meta_data' => ['options' => '1, 2, 3, 4, 5']
                    ],
                    [
                        'action_type' => 'SEND_MESSAGE',
                        'content' => 'Asante sana kwa maoni yako! Hii itatusaidia kuboresha huduma zetu siku za mbele.',
                        'meta_data' => ['type' => 'text']
                    ]
                ]
            ])
        ],
        [
            'title' => 'Sales Inquiry Handler',
            'description' => 'Engages potential buyers, checks budget, and assigns high-value leads to agents.',
            'category' => 'Sales',
            'icon_class' => 'fas fa-chart-line',
            'workflow_data' => json_encode([
                'trigger_type' => 'KEYWORD',
                'keywords' => 'bei, offer, price, cost, nunua',
                'steps' => [
                    [
                        'action_type' => 'SEND_MESSAGE',
                        'content' => 'Habari! Nimeona unahitaji kujua zaidi kuhusu bidhaa na bei zetu.',
                        'meta_data' => ['type' => 'text']
                    ],
                    [
                        'action_type' => 'DELAY',
                        'content' => '2', // 2 minutes
                        'meta_data' => []
                    ],
                    [
                        'action_type' => 'ASK_QUESTION',
                        'content' => 'Je, bajeti yako ikoje kwa sasa ili nikushauri vizuri?',
                        'meta_data' => ['options' => 'Chini ya 50k, 50k - 100k, Juu ya 100k']
                    ],
                    [
                        'action_type' => 'ADD_TAG',
                        'content' => 'Sales Inquiry',
                        'meta_data' => []
                    ],
                    [
                        'action_type' => 'ASSIGN_AGENT',
                        'content' => 'Fewest Conversations',
                        'meta_data' => []
                    ]
                ]
            ])
        ]
    ];

    // 3. Insert new templates
    $stmt = $pdo->prepare("INSERT INTO workflow_templates (title, description, category, icon_class, workflow_data) VALUES (:title, :description, :category, :icon_class, :workflow_data)");

    foreach ($templates as $t) {
        $stmt->execute([
            ':title' => $t['title'],
            ':description' => $t['description'],
            ':category' => $t['category'],
            ':icon_class' => $t['icon_class'],
            ':workflow_data' => $t['workflow_data']
        ]);
        echo "Inserted template: " . $t['title'] . "\n";
    }

    echo "Done! 3 Default workflows created successfully.\n";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
