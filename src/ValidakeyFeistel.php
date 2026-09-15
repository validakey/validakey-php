<?php

declare(strict_types=1);

namespace Validakey;

if (!class_exists('Validakey\\ValidakeyFeistel')) {
  class ValidakeyFeistel {

    private string $key;
    private int $rounds;
    private int $blockSize = 8; // 64-bit blocks (8 bytes)
    private string $hashAlgo = 'sha256';

    public function __construct(string $key, array $opts = []) {
        $this->key = $key;
        $this->rounds = (isset($opts['rounds']))?$opts['rounds']:8;
        $this->blockSize = (isset($opts['blockSize']))?$opts['blockSize']:8;
        $this->hashAlgo = (isset($opts['hashAlgo']))?$opts['hashAlgo']: 'sha256';
    }

    /**
     * Non-linear round function (F) using SHA-256
     */
    private function f(string $rightHalf, int $roundNum): string {
        $hash = hash_hmac($this->hashAlgo, $rightHalf . '_' . $roundNum, $this->key, true);
        return substr($hash, 0, 4); // Return exactly 4 bytes (32 bits)
    }

    /**
     * Encrypts a single 8-byte block
     */
    private function encryptBlock(string $block): string {
        // Split 8 bytes into two 4-byte halves
        $l = substr($block, 0, 4);
        $r = substr($block, 4, 4);

        for ($i = 0; $i < $this->rounds; $i++) {
            $nextR = $l ^ $this->f($r, $i);
            $l = $r;
            $r = $nextR;
        }

        // Swap back the final round to maintain symmetry
        return $r . $l;
    }

    /**
     * Decrypts a single 8-byte block
     */
    private function decryptBlock(string $block): string {
        // Split 8 bytes into two 4-byte halves
        $l = substr($block, 0, 4);
        $r = substr($block, 4, 4);

        // Run the rounds exactly in reverse order
        for ($i = $this->rounds - 1; $i >= 0; $i--) {
            $nextR = $l ^ $this->f($r, $i);
            $l = $r;
            $r = $nextR;
        }

        return $r . $l;
    }

    /**
     * Obfuscates text of any length using PKCS#7 padding and ECB block mode
     */
    public function encrypt(string $text): string {
        // Apply PKCS#7 padding to make text a multiple of 8 bytes
        $padLen = $this->blockSize - (strlen($text) % $this->blockSize);
        $text .= str_repeat(chr($padLen), $padLen);

        $cipherText = '';
        $chunks = str_split($text, $this->blockSize);

        foreach ($chunks as $chunk) {
            $cipherText .= $this->encryptBlock($chunk);
        }

        return bin2hex($cipherText);
    }

    /**
     * De-obfuscates the hex string back to original text.
     *
     * Throws rather than returning garbage: a wrong key is the expected case
     * while the server walks candidate keys, and PKCS#7 validation rejects
     * roughly 255 of every 256 wrong keys before any further checks run.
     *
     * @throws \InvalidArgumentException When the input is not a well-formed block-aligned hex string.
     * @throws \RuntimeException         When the padding does not validate (wrong key or corrupt data).
     */
    public function decrypt(string $hexCipher): string {
        if ('' === $hexCipher || 0 !== strlen($hexCipher) % 2 || 1 !== preg_match('/^[0-9a-fA-F]+$/', $hexCipher)) {
            throw new \InvalidArgumentException('Ciphertext is not a valid hex string.');
        }

        $cipherText = hex2bin($hexCipher);
        if (false === $cipherText || 0 !== strlen($cipherText) % $this->blockSize) {
            throw new \InvalidArgumentException('Ciphertext length is not a multiple of the block size.');
        }

        $plainText = '';
        $chunks = str_split($cipherText, $this->blockSize);

        foreach ($chunks as $chunk) {
            $plainText .= $this->decryptBlock($chunk);
        }

        // Validate PKCS#7 padding before stripping it.
        $padLen = ord(substr($plainText, -1));
        if ($padLen < 1 || $padLen > $this->blockSize || $padLen > strlen($plainText)) {
            throw new \RuntimeException('Invalid padding: wrong key or corrupt ciphertext.');
        }
        if (substr($plainText, -$padLen) !== str_repeat(chr($padLen), $padLen)) {
            throw new \RuntimeException('Invalid padding: wrong key or corrupt ciphertext.');
        }

        return substr($plainText, 0, -$padLen);
    }
  }
}
