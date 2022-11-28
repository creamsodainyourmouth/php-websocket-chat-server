<?php

namespace Src;
use MiladRahimi\Jwt\Exceptions\InvalidSignatureException;
use MiladRahimi\Jwt\Exceptions\InvalidTokenException;
use MiladRahimi\Jwt\Exceptions\JsonDecodingException;
use MiladRahimi\Jwt\Exceptions\SigningException;
use MiladRahimi\Jwt\Generator;
use MiladRahimi\Jwt\Parser;
use MiladRahimi\Jwt\Cryptography\Algorithms\Hmac\HS256;
use MiladRahimi\Jwt\Cryptography\Verifier;
use MiladRahimi\Jwt\Exceptions\ValidationException;
use MiladRahimi\Jwt\Validator\DefaultValidator;
use MiladRahimi\Jwt\Validator\Rule;
use MiladRahimi\Jwt\Validator\Rules\EqualsTo;
use MiladRahimi\Jwt\Validator\Rules\OlderThanOrSame;

class JWT
{
    private string $secret;
    private Verifier $signer;
    private Parser $parser;
    private DefaultValidator $validator;

    /**
     * @throws \MiladRahimi\Jwt\Exceptions\InvalidKeyException
     */
    public function __construct(string $secret_key)
    {
        $this->secret = $secret_key;
        $this->signer = new HS256($this->secret);
    }

    private function init_validator()
    {
        $this->validator = new DefaultValidator();
    }

    private function init_parser()
    {
        $this->parser = new Parser($this->signer, $this->validator);
    }

    public function add_rule(string $claimName, Rule $rule, bool $required = true)
    {
        $this->validator->addRule('is-admin', new EqualsTo(true), $required);
    }

    public function validate_token(string $jwt): bool
    {
        $this->init_validator();
        $this->init_parser();
        try {
            $claims = $this->parser->parse($jwt);
            return true;
        } catch (ValidationException $e) {
            echo "ValidationException\n";
            echo $e->getMessage();
        } catch (InvalidSignatureException $e) {
            echo "InvalidSignatureException\n";
        } catch (InvalidTokenException $e) {
            echo "InvalidTokenException\n";
        } catch (JsonDecodingException $e) {
            echo "JsonDecodingException\n";
        } catch (SigningException $e) {
            echo "SigningException\n";

        }
        return false;

    }
}