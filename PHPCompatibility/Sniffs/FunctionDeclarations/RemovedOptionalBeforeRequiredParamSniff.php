<?php
/**
 * PHPCompatibility, an external standard for PHP_CodeSniffer.
 *
 * @package   PHPCompatibility
 * @copyright 2012-2020 PHPCompatibility Contributors
 * @license   https://opensource.org/licenses/LGPL-3.0 LGPL3
 * @link      https://github.com/PHPCompatibility/PHPCompatibility
 */

namespace PHPCompatibility\Sniffs\FunctionDeclarations;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Util\Tokens;
use PHPCompatibility\Helpers\ScannedCode;
use PHPCompatibility\Sniff;
use PHPCSUtils\Tokens\Collections;
use PHPCSUtils\Utils\FunctionDeclarations;

/**
 * Declaring an optional function parameter before a required parameter is deprecated since PHP 8.0.
 *
 * > Declaring a required parameter after an optional one is deprecated. As an
 * > exception, declaring a parameter of the form "Type $param = null" before
 * > a required one continues to be allowed, because this pattern was sometimes
 * > used to achieve nullable types in older PHP versions.
 *
 * While deprecated since PHP 8.0, optional parameters with an explicitly nullable type
 * and a null default value, and found before a required parameter, are only flagged since PHP 8.1.
 *
 * PHP version 8.0
 * PHP version 8.1
 *
 * @link https://github.com/php/php-src/blob/69888c3ff1f2301ead8e37b23ff8481d475e29d2/UPGRADING#L145-L151
 * @link https://github.com/php/php-src/commit/c939bd2f10b41bced49eb5bf12d48c3cf64f984a
 *
 * @since 10.0.0
 */
class RemovedOptionalBeforeRequiredParamSniff extends Sniff
{

    /**
     * Base message for the PHP 8.0 deprecation.
     *
     * @var string
     */
    const PHP80_MSG = 'Declaring an optional parameter before a required parameter is deprecated since PHP 8.0.';

    /**
     * Base message for the PHP 8.1 deprecation.
     *
     * @var string
     */
    const PHP81_MSG = 'Declaring an optional parameter with a nullable type before a required parameter is soft deprecated since PHP 8.0 and hard deprecated since PHP 8.1';

    /**
     * Message template for detailed information about the deprecation.
     *
     * @var string
     */
    const MSG_DETAILS = ' Parameter %1$s is optional, while parameter %2$s is required. The %1$s parameter is implicitly treated as a required parameter.';

    /**
     * Tokens allowed in the default value.
     *
     * This property will be enriched in the register() method.
     *
     * @since 10.0.0
     *
     * @var array<int|string, int|string>
     */
    private $allowedInDefault = [
        \T_NULL => \T_NULL,
    ];

    /**
     * Returns an array of tokens this test wants to listen for.
     *
     * @since 10.0.0
     *
     * @return array<int|string>
     */
    public function register()
    {
        $this->allowedInDefault += Tokens::$emptyTokens;

        return Collections::functionDeclarationTokens();
    }

    /**
     * Processes this test, when one of its tokens is encountered.
     *
     * @since 10.0.0
     *
     * @param \PHP_CodeSniffer\Files\File $phpcsFile The file being scanned.
     * @param int                         $stackPtr  The position of the current token
     *                                               in the stack passed in $tokens.
     *
     * @return void
     */
    public function process(File $phpcsFile, $stackPtr)
    {
        if (ScannedCode::shouldRunOnOrAbove('8.0') === false) {
            return;
        }

        // Get all parameters from the function signature.
        $parameters = FunctionDeclarations::getParameters($phpcsFile, $stackPtr);
        if (empty($parameters)) {
            return;
        }

        $requiredParam = null;
        $parameters    = \array_reverse($parameters);

        // Walk the parameters in reverse order (from last to first).
        foreach ($parameters as $key => $param) {
            /*
             * Ignore variadic parameters, which are optional by nature.
             * These always have to be declared last and this has been this way since their introduction.
             */
            if ($param['variable_length'] === true) {
                continue;
            }

            if (isset($param['default']) === false) {
                $requiredParam = $param['name'];
                continue;
            }

            // Found an optional parameter.
            if (isset($requiredParam) === false) {
                // No required params found yet.
                continue;
            }

            // Okay, so we have an optional parameter before a required one.
            // Note: as this will never be the _last_ parameter, we can be sure the 'comma_token' will be set to a token and not `false`.
            $hasNull    = $phpcsFile->findNext(\T_NULL, $param['default_token'], $param['comma_token']);
            $hasNonNull = $phpcsFile->findNext($this->allowedInDefault, $param['default_token'], $param['comma_token'], true);

            // Check if it's typed with a non-nullable type and has a null default value, in which case we can ignore it.
            if ($param['type_hint'] !== ''
                && $param['nullable_type'] === false
                && ($hasNull !== false && $hasNonNull === false)
            ) {
                continue;
            }

            // Found an optional parameter with a required param after it.
            $error = self::PHP80_MSG . self::MSG_DETAILS;
            $code  = 'Deprecated80';
            $data  = [
                $param['name'],
                $requiredParam,
            ];

            if ($param['nullable_type'] === true && $hasNull !== false) {
                // Skip flagging the issue if the codebase doesn't need to run on PHP 8.1+.
                if (ScannedCode::shouldRunOnOrAbove('8.1') === false) {
                    continue;
                }

                $error = self::PHP81_MSG . self::MSG_DETAILS;
                $code  = 'Deprecated81';
            }

            $phpcsFile->addWarning($error, $param['token'], $code, $data);
        }
    }
}
