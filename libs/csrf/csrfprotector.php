<?php
include __DIR__ ."/csrfpCookieConfig.php";            //// cookie config class
include __DIR__ ."/csrfpDefaultLogger.php";           //// Logger class
include __DIR__ ."/csrfpAction.php";                  //// Actions enumerator

if (!defined('__CSRF_PROTECTOR__')) {
    define('__CSRF_PROTECTOR__', true);     // to avoid multiple declaration errors

    // name of HTTP POST variable for authentication
    define("CSRFP_TOKEN","csrfp_token");

    // We insert token name and list of url patterns for which
    // GET requests are validated against CSRF as hidden input fields
    // these are the names of the input fields
    define("CSRFP_FIELD_TOKEN_NAME", "csrfp_hidden_data_token");
    define("CSRFP_FIELD_URLS", "csrfp_hidden_data_urls");

    // Include the csrfpCookieConfig class

    /**
     * child exception classes
     */
    class configFileNotFoundException extends \exception {};
    class jsFileNotFoundException extends \exception {};
    class baseJSFileNotFoundExceptio extends \exception {};
    class incompleteConfigurationException extends \exception {};
    class alreadyInitializedException extends \exception {};

    class csrfProtector
    {
        /*
         * Variable: $isSameOrigin
         * flag for cross origin/same origin request
         * @var bool
         */
        private static $isSameOrigin = true;

        /*
         * Variable: $isValidHTML
         * flag to check if output file is a valid HTML or not
         * @var bool
         */
        private static $isValidHTML = false;

        /**
         * Variable: $cookieConfig
         * Array of parameters for the setcookie method
         * @var array<any>
         */
        private static $cookieConfig = null;
        
        /**
         * Variable: $logger
         * Logger class object
         * @var LoggerInterface
         */
        private static $logger = null;

        /**
         * Variable: $tokenHeaderKey
         * Key value in header array, which contain the token
         * @var string
         */
        private static $tokenHeaderKey = null;

        /*
         * Variable: $requestType
         * Variable to store whether request type is post or get
         * @var string
         */
        protected static $requestType = "GET";

        /*
         * Variable: $config
         * config file for CSRFProtector
         * @var int Array, length = 6
         * Property: #1: failedAuthAction (int) => action to be taken in case autherisation fails
         * Property: #2: logDirectory (string) => directory in which log will be saved
         * Property: #3: customErrorMessage (string) => custom error message to be sent in case
         *                        of failed authentication
         * Property: #4: jsFile (string) => location of the CSRFProtector js file
         * Property: #5: tokenLength (int) => default length of hash
         * Property: #6: disabledJavascriptMessage (string) => error message if client's js is disabled
         */
        public static $config = array();

        /*
         * Variable: $requiredConfigurations
         * Contains list of those parameters that are required to be there
         *     in config file for csrfp to work
         */
        public static $requiredConfigurations  = array('logDirectory', 'failedAuthAction', 'jsUrl', 'tokenLength');
        
        /*
         *    Function: init
          *
         *    function to initialise the csrfProtector work flow
         *
         *    Parameters:
         *    $length - length of CSRF_AUTH_TOKEN to be generated
         *    $action - int array, for different actions to be taken in case of failed validation
         *    $logger - custom logger class object
         *
         *    Returns:
         *        void
         *
         *    Throws:
         *        configFileNotFoundException - when configuration file is not found
         *         incompleteConfigurationException - when all required fields in config
         *                                            file are not available
         *
         */
        public static function init($config, $length = null, $action = null, $logger = null)
        {
            /*
             * Check if init has already been called.
             */
             if (count(self::$config) > 0) {
                 throw new alreadyInitializedException("OWASP CSRFProtector: library was already initialized.");
             }

            /*
             * if mod_csrfp already enabled, no verification, no filtering
             * Already done by mod_csrfp
             */
            if (getenv('mod_csrfp_enabled'))
                return;

            // start session in case its not, and unit test is not going on
            if (session_id() == '' && !defined('__CSRFP_UNIT_TEST__'))
                session_start();

            /*
             * load configuration file and properties
             * Check locally for a config.php then check for 
             * a config/csrf_config.php file in the root folder
             * for composer installations
             */
            if (is_string($config)) {
                if (!file_exists($config)) {
                    throw new configFileNotFoundException("OWASP CSRFProtector: configuration file not found for CSRFProtector!");
                }

                // Path to the config file
                $config = require $config;
            }

            if (is_object($config)) {
                $config = (array) $config;
            }
            self::$config = $config;

            //overriding length property if passed in parameters
            if ($length != null)
                self::$config['tokenLength'] = intval($length);
            
            //action that is needed to be taken in case of failed authorisation
            if ($action != null)
                self::$config['failedAuthAction'] = $action;

            if (self::$config['CSRFP_TOKEN'] == '')
                self::$config['CSRFP_TOKEN'] = CSRFP_TOKEN;

            self::$tokenHeaderKey = 'HTTP_' .strtoupper(self::$config['CSRFP_TOKEN']);
            self::$tokenHeaderKey = str_replace('-', '_', self::$tokenHeaderKey);

            // load parameters for setcookie method
            if (!isset(self::$config['cookieConfig']))
                self::$config['cookieConfig'] = array();
            self::$cookieConfig = new csrfpCookieConfig(self::$config['cookieConfig']);

            // Validate the config if everything is filled out
            $missingConfiguration = [];
            foreach (self::$requiredConfigurations as $value) {
                if (!isset(self::$config[$value]) || self::$config[$value] === '') {
                    $missingConfiguration[] = $value;
                }
            }

            if ($missingConfiguration) {
                throw new incompleteConfigurationException(
                    'OWASP CSRFProtector: Incomplete configuration file: missing ' .
                    implode(', ', $missingConfiguration) . ' value(s)');
            }
            
            // iniialize the logger class
            if ($logger !== null) {
                self::$logger = $logger;
            } else {
                self::$logger = new csrfpDefaultLogger(self::$config['logDirectory']);
            }

            // Authorise the incoming request
            self::authorizePost();

            // Initialize output buffering handler
            if (!defined('__TESTING_CSRFP__'))
                ob_start('csrfProtector::ob_handler');

            if (!isset($_COOKIE[self::$config['CSRFP_TOKEN']])
                || !isset($_SESSION[self::$config['CSRFP_TOKEN']])
                || !is_array($_SESSION[self::$config['CSRFP_TOKEN']])
                || !in_array($_COOKIE[self::$config['CSRFP_TOKEN']],
                    $_SESSION[self::$config['CSRFP_TOKEN']]))
                self::refreshToken();
        }

        /*
         * Function: authorizePost
         * function to authorise incoming post requests
         *
         * Parameters: 
         * void
         *
         * Returns: 
         * void
         *
         * Throws: 
         * logDirectoryNotFoundException - if log directory is not found
         */
        public static function authorizePost()
        {
            //#todo this method is valid for same origin request only, 
            //enable it for cross origin also sometime
            //for cross origin the functionality is different
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {

                //set request type to POST
                self::$requestType = "POST";

                // look for token in payload else from header
                $token = self::getTokenFromRequest();

                //currently for same origin only
                if (!($token && isset($_SESSION[self::$config['CSRFP_TOKEN']])
                    && (self::isValidToken($token)))) {

                    //action in case of failed validation
                    self::failedValidationAction();
                } elseif (self::$config['refreshTokenOnSuccess']) {
                    self::refreshToken();    //refresh token for successful validation
                }
            } else if (!static::isURLallowed()) {
                //currently for same origin only
                if (!(isset($_GET[self::$config['CSRFP_TOKEN']]) 
                    && isset($_SESSION[self::$config['CSRFP_TOKEN']])
                    && (self::isValidToken($_GET[self::$config['CSRFP_TOKEN']]))
                    )) {

                    //action in case of failed validation
                    self::failedValidationAction();
                } elseif (self::$config['refreshTokenOnSuccess']) {
                    self::refreshToken();    //refresh token for successful validation
                }
            }    
        }

        /*
         * Function: getTokenFromRequest
         * function to get token in case of POST request
         *
         * Parameters: 
         * void
         *
         * Returns: 
         * any (string / bool) - token retrieved from header or form payload
         */
        private static function getTokenFromRequest() {
            // TEMPORARY FIX: look for in $_COOKIE, then others
            if (isset($_COOKIE[self::$config['CSRFP_TOKEN']])) {
                return $_COOKIE[self::$config['CSRFP_TOKEN']];
            }

            // look for in $_POST, then header
            if (isset($_POST[self::$config['CSRFP_TOKEN']])) {
                return $_POST[self::$config['CSRFP_TOKEN']];
            }

            if (function_exists('apache_request_headers')) {
                $apacheRequestHeaders = apache_request_headers();
                if (isset($apacheRequestHeaders[self::$config['CSRFP_TOKEN']])) {
                    return $apacheRequestHeaders[self::$config['CSRFP_TOKEN']];
                }
            }

            if (self::$tokenHeaderKey === null) return false;
            if (isset($_SERVER[self::$tokenHeaderKey])) {
                return $_SERVER[self::$tokenHeaderKey];
            }

            return false;
        }

        /*
         * Function: isValidToken
         * function to check the validity of token in session array
         * Function also clears all tokens older than latest one
         *
         * Parameters: 
         * $token - the token sent with GET or POST payload
         *
         * Returns: 
         * bool - true if its valid else false
         */
        private static function isValidToken($token) {
            if (!isset($_SESSION[self::$config['CSRFP_TOKEN']])) return false;
            if (!is_array($_SESSION[self::$config['CSRFP_TOKEN']])) return false;
            foreach ($_SESSION[self::$config['CSRFP_TOKEN']] as $key => $value) {
                if ($value == $token) {

                    // Clear all older tokens assuming they have been consumed
                    foreach ($_SESSION[self::$config['CSRFP_TOKEN']] as $_key => $_value) {
                        if ($_value == $token) break;
                        array_shift($_SESSION[self::$config['CSRFP_TOKEN']]);
                    }
                    return true;
                }
            }

            return false;
        }

        /*
         * Function: failedValidationAction
         * function to be called in case of failed validation
         * performs logging and take appropriate action
         *
         * Parameters: 
         * void
         *
         * Returns: 
         * void
         */
        private static function failedValidationAction()
        {
            //call the logging function
            static::logCSRFattack();

            //#todo: ask mentors if $failedAuthAction is better as an int or string
            //default case is case 0
            switch (self::$config['failedAuthAction'][self::$requestType]) {
                case csrfpAction::ForbiddenResponseAction:
                    //send 403 header
                    header('HTTP/1.0 403 Forbidden');
                    exit("<h2>403 Access Forbidden by CSRFProtector!</h2>");
                    break;
                case csrfpAction::ClearParametersAction:
                    //unset the query parameters and forward
                    if (self::$requestType === 'GET') {
                        $_GET = array();
                    } else {
                        $_POST = array();
                    }
                    break;
                case csrfpAction::RedirectAction:
                    //redirect to custom error page
                    $location  = self::$config['errorRedirectionPage'];
                    header("location: $location");
                    exit(self::$config['customErrorMessage']);
                    break;
                case csrfpAction::CustomErrorMessageAction:
                    //send custom error message
                    exit(self::$config['customErrorMessage']);
                    break;
                case csrfpAction::InternalServerErrorResponseAction:
                    //send 500 header -- internal server error
                    header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error', true, 500);
                    exit("<h2>500 Internal Server Error!</h2>");
                    break;
                default:
                    //unset the query parameters and forward
                    if (self::$requestType === 'GET') {
                        $_GET = array();
                    } else {
                        $_POST = array();
                    }
                    break;
            }        
        }

        /*
         * Function: refreshToken
         * Function to set auth cookie
         *
         * Parameters: 
         * void
         *
         * Returns: 
         * void
         */
        public static function refreshToken()
        {
            $token = self::generateAuthToken();

            if (!isset($_SESSION[self::$config['CSRFP_TOKEN']])
                || !is_array($_SESSION[self::$config['CSRFP_TOKEN']]))
                $_SESSION[self::$config['CSRFP_TOKEN']] = array();

            // set token to session for server side validation
            array_push($_SESSION[self::$config['CSRFP_TOKEN']], $token);

            // set token to cookie for client side processing
            if (self::$cookieConfig === null) {
                if (!isset(self::$config['cookieConfig']))
                    self::$config['cookieConfig'] = array();
                self::$cookieConfig = new csrfpCookieConfig(self::$config['cookieConfig']);
            }

            setcookie(
                self::$config['CSRFP_TOKEN'], 
                $token,
                time() + self::$cookieConfig->expire,
                self::$cookieConfig->path,
                self::$cookieConfig->domain,
                (bool) self::$cookieConfig->secure);
        }

        /*
         * Function: generateAuthToken
         * function to generate random hash of length as given in parameter
         * max length = 128
         *
         * Parameters: 
         * length to hash required, int
         *
         * Returns:
         * string, token
         */
        public static function generateAuthToken()
        {
            // todo - make this a member method / configurable
            $randLength = 64;
            
            //if config tokenLength value is 0 or some non int
            if (intval(self::$config['tokenLength']) == 0) {
                self::$config['tokenLength'] = 32;    //set as default
            }

            //#todo - if $length > 128 throw exception 

            if (function_exists("random_bytes")) {
                $token = bin2hex(random_bytes($randLength));
            } elseif (function_exists("openssl_random_pseudo_bytes")) {
                $token = bin2hex(openssl_random_pseudo_bytes($randLength));
            } else {
                $token = '';
                for ($i = 0; $i < 128; ++$i) {
                    $r = mt_rand (0, 35);
                    if ($r < 26) {
                        $c = chr(ord('a') + $r);
                    } else { 
                        $c = chr(ord('0') + $r - 26);
                    }
                    $token .= $c;
                }
            }
            return substr($token, 0, self::$config['tokenLength']);
        }

        /*
         * Function: ob_handler
         * Rewrites <form> on the fly to add CSRF tokens to them. This can also
         * inject our JavaScript library.
         *
         * Parameters: 
         * $buffer - output buffer to which all output are stored
         * $flag - INT
         *
         * Return:
         * string, complete output buffer
         */
        public static function ob_handler($buffer, $flags)
        {
            // Even though the user told us to rewrite, we should do a quick heuristic
            // to check if the page is *actually* HTML. We don't begin rewriting until
            // we hit the first <html tag.
            if (!self::$isValidHTML) {
                // not HTML until proven otherwise
                if (stripos($buffer, '<html') !== false) {
                    self::$isValidHTML = true;
                } else {
                    return $buffer;
                }
            }

            // TODO: statically rewrite all forms as well so that if a form is submitted
            // before the js has worked on, it will still have token to send
            // @priority: medium @labels: important @assign: mebjas
            // @deadline: 1 week

            //add a <noscript> message to outgoing HTML output,
            //informing the user to enable js for CSRFProtector to work
            //best section to add, after <body> tag
            $buffer = preg_replace("/<body[^>]*>/", "$0 <noscript>" . self::$config['disabledJavascriptMessage'] .
                "</noscript>", $buffer);

            $hiddenInput = '<input type="hidden" id="' . CSRFP_FIELD_TOKEN_NAME.'" value="'
                            .self::$config['CSRFP_TOKEN'] .'">' .PHP_EOL;

            $hiddenInput .= '<input type="hidden" id="' .CSRFP_FIELD_URLS .'" value=\''
                            .json_encode(self::$config['verifyGetFor']) .'\'>';

            //implant hidden fields with check url information for reading in javascript
            $buffer = str_ireplace('</body>', $hiddenInput . '</body>', $buffer);

            if (self::$config['jsUrl']) {
                //implant the CSRFGuard js file to outgoing script
                $script = '<script type="text/javascript" src="' . self::$config['jsUrl'] . '"></script>';
                $buffer = str_ireplace('</body>', $script . PHP_EOL . '</body>', $buffer, $count);

                // Add the script to the end if the body tag was not closed
                if (!$count)
                    $buffer .= $script;
            }

            return $buffer;
        }

        /*
         * Function: logCSRFattack
         * Function to log CSRF Attack
         * 
         * Parameters: 
         * void
         *
         * Returns:
         * void
         *
         * Throws: 
         * logFileWriteError - if unable to log an attack
         */
        protected static function logCSRFattack()
        {
            //miniature version of the log
            $context = array();
            $context['HOST'] = $_SERVER['HTTP_HOST'];
            $context['REQUEST_URI'] = $_SERVER['REQUEST_URI'];
            $context['requestType'] = self::$requestType;
            $context['cookie'] = $_COOKIE;

            self::$logger->log("OWASP CSRF PROTECTOR VALIDATION FAILURE", $context);
        }

        /*
         * Function: getCurrentUrl
         * Function to return current url of executing page
         * 
         * Parameters: 
         * void
         *
         * Returns: 
         * string - current url
         */
        private static function getCurrentUrl()
        {
            $request_scheme = 'https';

            if (isset($_SERVER['REQUEST_SCHEME'])) {
                $request_scheme = $_SERVER['REQUEST_SCHEME'];
            } else {
                if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
                    $request_scheme = 'https';
                } else {
                    $request_scheme = 'http';
                }
            }

            return $request_scheme . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'];
        }

        /*
         * Function: isURLallowed
         * Function to check if a url matches for any urls
         * Listed in config file
         *
         * Parameters: 
         * void
         *
         * Returns: 
         * boolean - true is url need no validation, false if validation needed
         */  
        public static function isURLallowed() {
            foreach (self::$config['verifyGetFor'] as $key => $value) {
                $value = str_replace(array('/','*'), array('\/','(.*)'), $value);
                preg_match('/' .$value .'/', self::getCurrentUrl(), $output);
                if (count($output) > 0)
                    return false;
            }
            return true;
        }
    };
}
