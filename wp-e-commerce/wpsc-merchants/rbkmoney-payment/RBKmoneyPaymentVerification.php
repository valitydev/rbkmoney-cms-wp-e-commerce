<?php


class RBKmoneyPaymentVerification
{
    /**
     * Openssl verify
     */
    const OPENSSL_VERIFY_SIGNATURE_IS_CORRECT = 1;
    const OPENSSL_VERIFY_SIGNATURE_IS_INCORRECT = 0;
    const OPENSSL_VERIFY_ERROR = -1;

    public static function verification_signature($data = '', $signature = '', $public_key = '')
    {
        if (empty($data) || empty($signature) || empty($public_key)) {
            return FALSE;
        }
        $public_key_id = openssl_get_publickey($public_key);
        if (empty($public_key_id)) {
            return FALSE;
        }
        $verify = openssl_verify($data, $signature, $public_key_id, OPENSSL_ALGO_SHA256);
        return ($verify == static::OPENSSL_VERIFY_SIGNATURE_IS_CORRECT);
    }

}
