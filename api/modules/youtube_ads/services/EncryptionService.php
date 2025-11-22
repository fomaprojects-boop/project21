<?php

namespace Modules\YouTubeAds\Services;

class EncryptionService {
    private $key;
    private $cipher = 'AES-256-CBC';

    public function __construct() {
        // Inachukua Key kutoka config.php
        $this->key = defined('ENCRYPTION_KEY') ? ENCRYPTION_KEY : getenv('ENCRYPTION_KEY');

        if (empty($this->key)) {
            throw new \Exception('Encryption key is not set.');
        }
        if (strlen($this->key) !== 32) {
            throw new \Exception('Encryption key must be 32 bytes long.');
        }
    }

    /**
     * Encrypts plaintext with a key and authentication (HMAC)
     * @param string $plaintext
     * @return string
     * @throws \Exception
     */
    public function encrypt($plaintext) {
        if ($plaintext === null || $plaintext === '') {
            return null;
        }
        $iv = random_bytes(openssl_cipher_iv_length($this->cipher));
        $ciphertext = openssl_encrypt($plaintext, $this->cipher, $this->key, OPENSSL_RAW_DATA, $iv);
        $hmac = hash_hmac('sha256', $iv . $ciphertext, $this->key, true);
        return base64_encode($iv . $hmac . $ciphertext);
    }

    /**
     * Decrypts an encrypted and authenticated blob
     * @param string $blob
     * @return string|false
     */
    public function decrypt($blob) {
        if ($blob === null || $blob === '') {
            return null;
        }
        $data = base64_decode($blob);
        $ivLength = openssl_cipher_iv_length($this->cipher);
        $iv = substr($data, 0, $ivLength);
        $hmac = substr($data, $ivLength, 32);
        $ciphertext = substr($data, $ivLength + 32);

        if ($ciphertext === false || $iv === false || $hmac === false) {
            error_log("EncryptionService: Decrypt failed. Data structure invalid.");
            return false;
        }
        
        $calculatedHmac = hash_hmac('sha256', $iv . $ciphertext, $this->key, true);

        if (!hash_equals($hmac, $calculatedHmac)) {
            error_log("EncryptionService: Decrypt failed. HMAC validation failed. Data may be tampered.");
            return false; // Authentication failed
        }

        $plaintext = openssl_decrypt($ciphertext, $this->cipher, $this->key, OPENSSL_RAW_DATA, $iv);

        if ($plaintext === false) {
             error_log("EncryptionService: openssl_decrypt failed.");
        }
        
        return $plaintext;
    }
}