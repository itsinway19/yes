<?php

return [
    'verify' => [
        'title' => 'Verify your Builderad email',
        'intro' => 'Use this secure link to verify your account email. The link expires after 60 minutes.',
        'action' => 'Verify email',
        'outro' => 'If you did not create this account, ignore this message.',
    ],
    'email' => [
        'reauthenticate' => 'Your primary email changed. Sign in again and verify the new address before using the account.',
    ],
    'session' => [
        'reauthenticate' => 'Sign in again with your passkey before making this change.',
    ],
    'reset' => [
        'title' => 'Reset your Builderad password',
        'intro' => 'A password recovery request was made for this account. Use the link if you made the request.',
        'action' => 'Reset password',
        'outro' => 'This link expires after :minutes minutes and can be used once. If you did not request recovery, ignore this message.',
        'requested' => 'If this email belongs to an eligible account, a recovery message will be prepared for sending.',
    ],
    'notice' => [
        'outro' => 'If you did not make this change, start account recovery or contact Builderad support.',
        'password_changed' => [
            'title' => 'Your Builderad password changed',
            'intro' => 'Your account password changed. Other sessions were ended to protect your account.',
        ],
        'passkey_added' => [
            'title' => 'A passkey was added to your account',
            'intro' => 'A new passkey was registered for your Builderad account.',
        ],
        'passkey_removed' => [
            'title' => 'A passkey was removed from your account',
            'intro' => 'A passkey was removed from your Builderad account.',
        ],
        'primary_email_changed' => [
            'title' => 'Your Builderad primary email changed',
            'intro' => 'Your account primary email changed. Access is paused until the new email is verified.',
        ],
        'account_disabled' => [
            'title' => 'Your Builderad account was disabled',
            'intro' => 'Your account or internal eligibility was disabled, and active sessions were ended.',
        ],
    ],
];
