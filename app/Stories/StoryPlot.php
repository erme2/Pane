<?php

namespace App\Stories;

use App\Exceptions\SystemException;
use Illuminate\Http\Request;

class StoryPlot
{
    const VALID_CONTENT_TYPES = [
        'json' => 'application/json',
    ];

    protected string $contentType;
    public array $data = [];
    protected array $headers = [];
    protected array $log = [
        'errors' => [],
        'warnings' => [],
        'info' => [],
    ];
    public array $options = [];
    protected array $pagination = [];
    public array $requestData = [
        'data' => [],
        'headers' => [],
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
    public function __construct(string $contentType = 'application/json') {
        $this->setContentType($contentType);
    }

    /**
     * @return string
     * @test StoryPlotTest::testSetGetContentType_basic
     */
    public function getContentType(): string
    {
        return $this->contentType;
    }

    public function getStatus(): int
    {
        return $this->status ?? 0;
    }

    public function setRequestData(Request $request): StoryPlot
    {
        $this->requestData['data'] = $request->all();
        $this->requestData['headers'] = $request->headers->all();
        $this->requestData['method'] = $request->method();
        return $this;
    }

    public function setContentType(string $contentType): StoryPlot
    {
        if (!in_array($contentType, self::VALID_CONTENT_TYPES)) {
            throw new SystemException("Invalid content type: $contentType");
        }
        $this->contentType = $contentType;
        return $this;
    }

    /**
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

}
