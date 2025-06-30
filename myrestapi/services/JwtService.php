<?php
namespace MyRestApi\Services;

use Configuration;
use DateTimeImmutable;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Builder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;
use Context;

class JwtService
{
    private $signer;
    private $signingKey;
    private $issuer;
    private $permittee;

    public function __construct()
    {
        $this->signer = new Sha256();
        $secret = Configuration::get('MYRESTAPI_JWT_SECRET');
        if (!$secret) {
            // This should ideally not happen if module installation is correct
            throw new \Exception('JWT Secret is not configured.');
        }
        $this->signingKey = InMemory::plainText($secret);
        $this->issuer = Context::getContext()->shop->getBaseURL(true); // Use shop base URL as issuer
        $this->permittee = 'myrestapi_user'; // Audience for the token
    }

    public function generateToken(string $userId, array $claims = [], int $expiresIn = 3600): string
    {
        $builder = new Builder(new JoseEncoder(), ChainedFormatter::default());
        $now = new DateTimeImmutable();

        $token = $builder
            ->issuedBy($this->issuer)
            ->permittedFor($this->permittee)
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($now->modify('+' . $expiresIn . ' seconds'))
            ->withClaim('uid', $userId); // User identifier (e.g., API key ID or user ID)

        foreach ($claims as $key => $value) {
            $token = $token->withClaim($key, $value);
        }

        return $token->getToken($this->signer, $this->signingKey)->toString();
    }

    public function validateToken(string $tokenString): ?object
    {
        try {
            $parser = new Parser(new JoseEncoder());
            $token = $parser->parse($tokenString);

            $validator = new Validator();

            if (!$validator->validate($token, new IssuedBy($this->issuer))) {
                return null; // Invalid issuer
            }

            if (!$validator->validate($token, new PermittedFor($this->permittee))) {
                return null; // Invalid audience
            }

            if (!$validator->validate($token, new SignedWith($this->signer, $this->signingKey))) {
                return null; // Token signature is invalid
            }

            if ($token->isExpired(new DateTimeImmutable())) {
                return null; // Token expired
            }

            return (object) [
                'uid' => $token->claims()->get('uid'),
                'claims' => $token->claims()->all(),
            ];
        } catch (\Exception $e) {
            // Log error: error_log('JWT Validation Error: ' . $e->getMessage());
            return null; // Parsing failed or other validation issue
        }
    }

    public static function getAuthorizationHeader(): ?string
    {
        $header = null;
        if (isset($_SERVER['Authorization'])) {
            $header = trim($_SERVER["Authorization"]);
        } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) { // Nginx or fast CGI
            $header = trim($_SERVER["HTTP_AUTHORIZATION"]);
        } elseif (function_exists('apache_request_headers')) {
            $requestHeaders = apache_request_headers();
            // Server-side fix for bug in old Android versions (a nice side-effect of this fix means we don't care about capitalization for Authorization)
            $requestHeaders = array_combine(array_map('ucwords', array_keys($requestHeaders)), array_values($requestHeaders));
            if (isset($requestHeaders['Authorization'])) {
                $header = trim($requestHeaders['Authorization']);
            }
        }
        return $header;
    }

    public static function getBearerToken(): ?string
    {
        $header = self::getAuthorizationHeader();
        if (!empty($header)) {
            if (preg_match('/Bearer\s(\S+)/', $header, $matches)) {
                return $matches[1];
            }
        }
        return null;
    }
}
