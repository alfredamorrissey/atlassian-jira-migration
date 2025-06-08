<?php
namespace Uo\AtlassianJiraMigration\Utils;

use Uo\AtlassianJiraMigration\Exception\JiraApiException;
use CURLFile;
use Monolog\Logger;
use Uo\AtlassianJiraMigration\Utils\LoggerFactory;

/***
 * A PHP class to interact with the Atlassian API.
 * This class provides methods to make GET, POST, PUT, and DELETE requests to the Atlassian API.
 * It includes methods to set the base URL, authentication credentials, and to handle API requests with error handling.
 * @link https://developer.atlassian.com/cloud/jira/platform/rest/v3/intro/
 * @example
 * $api = new AtlassianAPI('https://your-domain.atlassian.net', 'username', 'api_token');
 * $response = $api->get('/rest/api/3/issue/123');
 * print_r($response);   
 */
class AtlassianAPI {
    private $baseUrl;
    private $httpCode = null;
    private Logger $log;
    
    // Common headers
    protected $headers = null;
    // Headers for file uploads
    protected $headersForUpload = null;
    
    /**
     * Constructor to initialize the Atlassian API client.
     *
     * @param string $baseUrl The base URL of the Atlassian instance (e.g., 'https://your-domain.atlassian.net').
     * @param string $username The username for authentication.
     * @param string $apiToken The API token for authentication.
     */
    public function __construct(string $baseUrl, string $username, string $apiToken) {
        $this->baseUrl = $baseUrl;
        $auth = base64_encode("$username:$apiToken");
        $this->headers = [
            "Authorization: Basic $auth",
            "Content-Type: application/json",
            "Accept: application/json"
        ];        
        $this->headersForUpload = [
            "Authorization: Basic $auth",
            "Accept: application/json",
            "X-Atlassian-Token: no-check" // Required for file uploads
        ];

        $this->log = $logger ?? LoggerFactory::create('api_error');
    } 
    
    /**
     * Gets the HTTP status code of the most recent request.
     *
     * @return int The HTTP status code of the most recent request.
     */
    public function getHttpCode(): int {
        return $this->httpCode;
    }

    /**
     * Sends a GET request to the specified API endpoint.
     *
     * @param string $endpoint The API endpoint to send the GET request to.
     * @return mixed The decoded JSON response from the API.
     * @throws Exception If the HTTP response code is not 200 or 201.
     */

    public function get(string $endpoint): mixed {
        $url = $this->baseUrl . $endpoint;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); 
        $response = curl_exec($ch);
        curl_close($ch);
        $this->httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $curlError = curl_error($ch);
        if ($curlError) {
            throw new JiraApiException(
                "cURL error GET $: $curlError",
                'GET',
                $url,
                null,
                $this->httpCode,
                $response
            );
        }

        if ($response === false) {
            throw new JiraApiException(
                "cURL error GET $: " . curl_error($ch),
                'GET',
                $url,
                null,
                $this->httpCode,
                $response
            );
        }

        //Check if the response has errors
        if (is_array($response) && (!empty($response['errors'] || isset($response['errorMessages'])))) {
            $errorMessage = "GET request failed with errors: " . $response['errorMessages'];
            throw new JiraApiException(
                $errorMessage,
                'GET',
                $url,
                null,
                $this->httpCode,
                $response
            );
        }
        
        if ($this->httpCode !== 200 && $this->httpCode !== 201) {
            throw new JiraApiException(
                "GET request failed: HTTP code $this->httpCode",
                'GET',
                $url,
                null,
                $this->httpCode,
                $response
            );
        }
        return json_decode($response, true);
    }        

    /**
     * Sends a POST request to the specified API endpoint.
     *
     * @param string $endpoint The API endpoint to send the POST request to.
     * @param mixed $data The data to send with the POST request.
     * @return mixed The decoded JSON response from the API.
     * @throws Exception If the HTTP response code is not 200 or 201.
     */
    public function post(string $endpoint, mixed $data): mixed {
        $url = $this->baseUrl . $endpoint;
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE);
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $this->httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $responseBody = $response;
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new JiraApiException(
                "cURL error GET $: $curlError",
                'POST',
                $url,
                json_encode($data, JSON_UNESCAPED_UNICODE),
                $this->httpCode,
                $response
            );
        }

        if ($response === false) {
            throw new JiraApiException(
                "cURL error POST $: " . curl_error($ch),
                'POST',
                $url,
                $payload,
                $this->httpCode,
                $response
            );
        }

        //Check if the response has errors
        if (is_array($response) && (!empty($response['errors'] || isset($response['errorMessages'])))) {
            $errorMessage = "POST request failed with errors: " . $response['errorMessages'];
            $this->log->error($errorMessage);
            $this->log->error($url);
            $this->log->error($response);
            $this->log->error($payload);
            throw new JiraApiException(
                $errorMessage,
                'POST',
                $url,
                json_encode($data, JSON_UNESCAPED_UNICODE),
                $this->httpCode,
                $response
            );
        }
        
        if ($this->httpCode !== 200 && $this->httpCode !== 201  && $this->httpCode !== 204) {
            $errorMessage = "POST request failed: HTTP code $this->httpCode";
            $this->log->error($errorMessage);
            $this->log->error($url);
            $this->log->error($response);
            $this->log->error($payload);
            throw new JiraApiException(
                $errorMessage,
                'POST',
                $url,
                json_encode($data, JSON_UNESCAPED_UNICODE),
                $this->httpCode,
                $response
            );
        }
        return json_decode($response, true);
    }
    /**
     * Sends a PUT request to the specified API endpoint.
     *
     * @param string $endpoint The API endpoint to send the PUT request to.
     * @param mixed $data The data to send with the PUT request.
     * @return mixed The decoded JSON response from the API.
     * @throws Exception If the HTTP response code is not 200 or 201.
     */
    public function put(string $endpoint, mixed $data): mixed {
        $url = $this->baseUrl . $endpoint;
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $this->httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $curlError = curl_error($ch);
        if ($curlError) {
            throw new JiraApiException(
                "cURL error GET $: $curlError",
                'PUT',
                $url,
                json_encode($data, JSON_UNESCAPED_UNICODE),
                $this->httpCode,
                $response
            );
        }

        if ($response === false) {
            throw new JiraApiException(
                "cURL error PUT $: " . curl_error($ch),
                'PUT',
                $url,
                json_encode($data, JSON_UNESCAPED_UNICODE),
                $this->httpCode,
                $response
            );
        }

        //Check if the response has errors
        if (is_array($response) && (!empty($response['errors'] || isset($response['errorMessages'])))) {
            throw new JiraApiException(
                "PUT request failed with errors: " . $response['errorMessages'],
                'POST',
                $url,
                $payload,
                $this->httpCode,
                $response
            );
        }
        
        if ($this->httpCode !== 200 && $this->httpCode !== 201  && $this->httpCode !== 204) {
            $errorMessage = "PUT request failed: HTTP code $this->httpCode";
            $this->log->error($errorMessage);
            $this->log->error($url);
            $this->log->error($response);
            $this->log->error($payload);
            throw new JiraApiException(
                $errorMessage,
                'PUT',
                $url,
                $payload,
                $this->httpCode,
                $response
            );
        }
        return json_decode($response, true);
    }

    /**
     * Sends a file via a POST request to the specified API endpoint.
     *
     * @param string $endpoint The API endpoint to send the file to.
     * @param string $filePath The path to the file to be uploaded.
     * @return mixed The decoded JSON response from the API.
     * @throws Exception If the HTTP response code is not 200 or 201.
     */

    public function postFile(string $endpoint, string $filePath): mixed {
        $url = $this->baseUrl . $endpoint;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headersForUpload);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            'file' => new CURLFile(realpath($filePath))
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $this->httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $curlError = curl_error($ch);
        if ($curlError) {
            throw new JiraApiException(
                "cURL error GET $: $curlError",
                'PUT',
                $url,
                null,
                $this->httpCode,
                $response
            );
        }
        if ($response === false) {
            throw new JiraApiException(
                "cURL error POST $: " . curl_error($ch),
                'POST',
                $url,
                null,
                $this->httpCode,
                $response
            );
        }

        //Check if the response has errors
        if (is_array($response) && (!empty($response['errors'] || isset($response['errorMessages'])))) {
            $errorMessage = "POST request failed with errors: " . $response['errorMessages'];
            throw new JiraApiException(
                $errorMessage,
                'POST',
                $url,
                null,
                $this->httpCode,
                $response
            );
        }
        // Check if the HTTP response code is 200 or 201
        if ($this->httpCode !== 200 && $this->httpCode !== 201) {
            throw new JiraApiException(
                "PUT file request failed: HTTP code $this->httpCode",
                'PUT',
                $url,
                null,
                $this->httpCode,
                $response
            );
        }
        return json_decode($response, true);
    }
    
}
