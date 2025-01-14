<?php

namespace RREST;

use League\JsonGuard;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\Encoder\XmlEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use RREST\Router\RouterInterface;
use RREST\Exception\InvalidResponsePayloadBodyException;
use RREST\Exception\InvalidJSONException;

class Response
{
    /**
     * @var mixed
     */
    protected $content;

    /**
     * @var string
     */
    protected $format;

    /**
     * @var string
     */
    protected $statusCode;

    /**
     * @var string
     */
    protected $schema;

    /**
     * @var string[]
     */
    protected $supportedFormat = ['json', 'xml'];

    /**
     * @var RouterInterface
     */
    protected $router;

    /**
     * The URL of a resource, useful when POST a new one.
     *
     * @var string
     */
    protected $headerLocation;

    /**
     * @var string
     */
    protected $headerContentType;

    public function __construct(RouterInterface $router, $format, $statusCode)
    {
        $this->setFormat($format);
        $this->setRouter($router);
        $this->setStatusCode($statusCode);
    }

    /**
     * @return string
     */
    public function getFormat()
    {
        return $this->format;
    }

    /**
     * @param string
     */
    public function setFormat($format)
    {
        if (in_array($format, $this->supportedFormat) === false) {
            throw new \RuntimeException(
                'format not supported, only are '.implode(', ', $this->supportedFormat).' availables'
            );
        }
        $this->format = $format;
    }

    /**
     * @return string
     */
    public function getConfiguredHeaderstatusCode()
    {
        return $this->statusCode;
    }

    /**
     * @param string
     */
    public function setStatusCode($statusCode)
    {
        $this->statusCode = $statusCode;
    }

    /**
     * @return mixed
     */
    public function getLocation()
    {
        return $this->headerLocation;
    }

    /**
     * @param mixed
     */
    public function setLocation($headerLocation)
    {
        $this->headerLocation = $headerLocation;
    }

    /**
     * @return mixed
     */
    public function getContentType()
    {
        return $this->headerContentType;
    }

    /**
     * @param mixed
     */
    public function setContentType($headerContentType)
    {
        $this->headerContentType = $headerContentType;
    }

    /**
     * @param string
     */
    public function setSchema($schema)
    {
        $this->schema = $schema;
    }

    /**
     * @return string
     */
    public function getSchema()
    {
        return $this->schema;
    }

    /**
     * All headers configured, index by header name.
     *
     * @return string[]
     */
    public function getConfiguredHeaders()
    {
        $headers = [];
        $contentType = $this->getContentType();
        if (empty($contentType) === false) {
            $headers['Content-Type'] = $contentType;
        }
        $location = $this->getLocation();
        if (empty($location) === false) {
            $headers['Location'] = $location;
        }

        return $headers;
    }

    /**
     * @param mixed $content
     *
     * @return bool
     */
    public function setContent($content)
    {
        $this->content = $content;

        $this->assertReponseSchema(
            $this->getFormat(),
            $this->getSchema(),
            $content
        );
    }

    /**
     * @return mixed
     */
    public function getContent()
    {
        return $this->content;
    }

    /**
     * @param RouterInterface $router
     */
    public function setRouter(RouterInterface $router)
    {
        $this->router = $router;
    }

    /**
     * @return RouterInterface
     */
    public function getRouter()
    {
        return $this->router;
    }

    /**
     * Get a router configured response with:
     * - content serialize
     * - success status code
     * - header Content-Type
     * - header Location.
     *
     * @param bool $autoSerializeContent
     *
     * @return mixed
     */
    public function getRouterResponse($autoSerializeContent = true)
    {
        $content = $this->getContent();
        if ($autoSerializeContent) {
            $content = $this->serialize($content, $this->getFormat());
        }

        return $this->router->getResponse(
            $content, $this->getConfiguredHeaderstatusCode(), $this->getConfiguredHeaders()
        );
    }

    /**
     * @param mixed  $data
     * @param string $format
     *
     * @return string
     */
    public function serialize($data, $format)
    {
        if ($format === 'json') {
            return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } elseif ($format === 'xml') {
            $serializer = new Serializer([
                    new ObjectNormalizer(),
                ], [
                    'xml' => new XmlEncoder(),
                ]
            );
            //fix stdClass not serialize by default
            $data = json_decode(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true);

            return $serializer->serialize($data, $format);
        } else {
            throw new \RuntimeException(
                'format not supported, only are '.implode(', ', $this->supportedFormat).' availables'
            );
        }
    }

    /**
     * @param string $format
     * @param string $schema
     * @param string $value
     *
     * @throws InvalidResponsePayloadBodyException
     * @throws InvalidJSONException
     * @throws InvalidXMLException
     */
    public function assertReponseSchema($format, $schema, $value)
    {
        if (empty($schema)) {
            return;
        }

        switch (true) {
            case strpos($format, 'json') !== false:
                $this->assertResponseJSON($value, $schema);
                break;
            case strpos($format, 'xml') !== false:
                $this->assertResponseXML($value, $schema);
                break;
            default:
                throw new \RuntimeException(
                    'format not supported, only are '.implode(', ', $this->supportedFormat).' availables'
                );
                break;
        }
    }

    /**
     * @param string $value
     * @param string $schema
     *
     * @throws \RREST\Exception\InvalidXMLException
     * @throws \RREST\Exception\InvalidResponsePayloadBodyException
     */
    public function assertResponseXML($value, $schema)
    {
        $thowInvalidXMLException = function ($exceptionClassName) {
            $invalidBodyError = [];
            $libXMLErrors = libxml_get_errors();
            libxml_clear_errors();
            if (empty($libXMLErrors) === false) {
                foreach ($libXMLErrors as $libXMLError) {
                    $message = $libXMLError->message.' (line: '.$libXMLError->line.')';
                    $invalidBodyError[] = new Error(
                        $message,
                        'invalid-response-xml'
                    );
                }
                if (empty($invalidBodyError) == false) {
                    throw new $exceptionClassName($invalidBodyError);
                }
            }
        };

        //validate XML
        $originalErrorLevel = libxml_use_internal_errors(true);
        $valueDOM = new \DOMDocument();
        $valueDOM->loadXML($value);
        $thowInvalidXMLException('RREST\Exception\InvalidXMLException');

        //validate XMLSchema
        $valueDOM->schemaValidateSource($schema);
        $thowInvalidXMLException('RREST\Exception\InvalidResponsePayloadBodyException');

        libxml_use_internal_errors($originalErrorLevel);
    }

    /**
     * @param string $value
     * @param string $schema
     *
     * @throws \RREST\Exception\InvalidJSONException
     * @throws \RREST\Exception\InvalidResponsePayloadBodyException
     */
    public function assertResponseJSON($value, $schema)
    {
        $assertInvalidJSONException = function () {
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidJSONException([new Error(
                    ucfirst(json_last_error_msg()),
                    'invalid-response-payloadbody-json'
                )]);
            }
        };

        //validate JSON format
        $schemaJSON = json_decode($schema);
        $assertInvalidJSONException();

        //validate JsonSchema
        $deref = new JsonGuard\Dereferencer();
        $schema = $deref->dereference($schemaJSON);
        $validator = new JsonGuard\Validator($value, $schema);
        if ($validator->fails()) {
            $errors = $validator->errors();
            $jsonPointer = new JsonGuard\Pointer($value);
            $invalidBodyError = [];
            foreach ($validator->errors() as $jsonError) {
                $error = $jsonError->toArray();
                $propertyValue = null;
                try {
                    $propertyValue = $jsonPointer->get($error['pointer']);
                } catch (NonexistentValueReferencedException $e) {
                    //don't care if we can't have the value here, it's just
                    //for the context
                }
                $context = new \stdClass();
                $context->jsonPointer = $error['pointer'];
                $context->value = $propertyValue;
                $context->constraints = $error['context'];

                $invalidBodyError[] = new Error(
                    strtolower($error['pointer'].': '.$error['message']),
                    strtolower($error['keyword']),
                    $context
                );
            }
            if (empty($invalidBodyError) == false) {
                throw new InvalidResponsePayloadBodyException($invalidBodyError);
            }
        }
    }
}
