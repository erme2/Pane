<?php

namespace App\Stories;

use App\Exceptions\SystemException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\HeaderBag;

/**
 * Class StoryPlot
 * the story plot is the core of every story, and all the actions will use it to communicate with each other
 *
 * @package App\Stories
 */

class StoryPlot
{
    const array VALID_CONTENT_TYPES = [
        'json' => 'application/json',
    ];

    protected string $contentType;
    public array $data = [];
    public HeaderBag $headers {
        get {
            return $this->headers;
        }
    }
    protected array $log = [
        'errors' => [],
        'warnings' => [],
        'info' => [],
    ];
    public array $options = [];
    protected array $pagination = [];
    public array $requestData = [
        'data' => [],
        'method' => '',
    ];
    protected int $status;

    /**
     * Class constructor.
     *
     * @param string $contentType
     * @throws SystemException
     * @test StoryPlotTest::test__construct
     */
    public function __construct(string $contentType = 'application/json')
    {
        $this->setContentType($contentType);
    }

    /**
     * Saves a warning message to logs
     *
     * @param string $message
     * @return void
     */
    public function error(string $message): void
    {
        $this->log['errors'][] = $message;
    }

    /**
     * Gets the content type of the story plot
     *
     * @return string
     * @test StoryPlotTest::testSetGetContentType_basic
     */
    public function getContentType(): string
    {
        return $this->contentType;
    }

    /**
     * Returns the log data
     *
     * @param string|null $what (errors|warnings|info)
     * @return array|array[]
     */
    public function getLogs(?string $what = null): array
    {
        if ($what && isset($this->log[$what])) {
            return $this->log[$what];
        }
        return $this->log;
    }

    /**
     * Gets the status of the story plot
     *
     * @return int
     */
    public function getStatus(): int
    {
        return $this->status ?? 0;
    }

    /**
     * Gets the pagination data
     * @return array
     */
    public function getPagination(): array
    {
        return $this->pagination;
    }

    /**
     * Saves an info message to logs
     *
     * @param string $message
     * @return void
     */
    public function info(string $message): void
    {
        $this->log['info'][] = $message;
    }

    /**
     * Gets what we need from the request and save it in to the plot data
     *
     * @param Request $request
     * @return $this
     */
    public function setRequestData(Request $request): StoryPlot
    {
        $this->requestData['data'] = $request->all();
        $this->requestData['method'] = $request->method();
        $this->headers = $request->headers;
        return $this;
    }

    /**
     * Validates and set the content type of the story plot
     *
     * @param string $contentType
     * @return $this
     * @throws SystemException
     */
    public function setContentType(string $contentType): StoryPlot
    {
        if (!in_array($contentType, self::VALID_CONTENT_TYPES)) {
            throw new SystemException("Invalid content type: $contentType");
        }
        $this->contentType = $contentType;
        return $this;
    }

    /**
     * Validates and Set the status of the story plot
     *
     * @param int $status
     * @return $this
     * @throws SystemException
     * @test StoryPlotTest::testSetStatus
     */
    public function setStatus(int $status): StoryPlot
    {
        if ($status < 100 || $status > 599) {
            throw new SystemException("Invalid status code: $status");
        }
        $this->status = $status;
        return $this;
    }

    /**
     * Set the pagination data
     *
     * @param array $pagination
     * @return $this
     */
    public function setPagination(array $pagination): StoryPlot
    {
        $this->pagination = $pagination;
        return $this;
    }

    /**
     * Saves a warning message to logs
     *
     * @param string $message
     * @return void
     */
    public function warning(string $message): void
    {
        $this->log['warnings'][] = $message;
    }
}
