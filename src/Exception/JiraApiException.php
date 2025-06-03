<?php
namespace Uo\AtlassianJiraMigration\Exception;
use Exception;

/**
 * JiraApiException
 * 
 * This class represents an exception that is thrown when there is an error
 * while interacting with the Jira API. It includes details about the request
 * and response, such as the URL, payload, HTTP code, and response body.
 */
class JiraApiException extends \Exception
{
    public ?string $payload;
    public ?string $response;
    public string $url;
    public int $httpCode;
    public string $method;

    /**
     * Constructor
     *
     * @param string $message The error message
     * @param string $method The HTTP method used (GET, POST, PUT, DELETE)
     * @param string $url The URL of the API call
     * @param string|null $payload The payload of the API call
     * @param int $httpCode The HTTP code returned by the API
     * @param string|null $response The response body returned by the API
     *
     * @throws \InvalidArgumentException If an invalid HTTP method is provided
     */
    public function __construct(
        string $message,
        string $method,
        string $url,
        ?string $payload,
        int $httpCode,
        ?string $response
    ) {
        parent::__construct($message);
        $this->method = $method;
        if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE'])) {
            throw new \InvalidArgumentException("Invalid HTTP method: $method");
        }
        $this->url = $url;
        $this->payload = $payload;
        $this->httpCode = $httpCode;
        $this->response = $response;
    }

    /**
     * Converts the exception to a context array that can be used for error
     * reporting or logging.
     *
     * The returned array will contain the following keys:
     *
     * - `method`: The HTTP method used for the failed request.
     * - `url`: The URL of the failed request.
     * - `httpCode`: The HTTP status code returned by the API.
     * - `payload`: The payload of the API request, as a JSON-decoded array.
     * - `response`: The response body returned by the API, as a JSON-decoded
     *   array.
     *
     * @return array A context array containing information about the failed
     *   request.
     */
    public function toContextArray(): array
    {
        return [
            'method' => $this->method,
            'url' => $this->url,
            'httpCode' => $this->httpCode,
            'response' => $this->response ? json_decode($this->response, true) : null
        ];
    }
}
