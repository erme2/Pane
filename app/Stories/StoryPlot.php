<?php

namespace App\Stories;

use App\Exceptions\PaneException;
use Illuminate\Http\Response;

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
    protected array $pagination = [];
    protected int $status;

    /**
     * Class constructor.
     *
     * @param string $contentType
     * @throws PaneException
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


    public function setContentType(string $contentType): StoryPlot
    {
        if (!in_array($contentType, self::VALID_CONTENT_TYPES)) {
            throw new PaneException("Invalid content type: $contentType");
        }
        $this->contentType = $contentType;
        return $this;
    }

    /**
     * @param int $status
     * @return $this
     * @throws PaneException
     * @test StoryPlotTest::testSetStatus
     */
    public function setStatus(int $status): StoryPlot
    {
        if ($status < 100 || $status > 599) {
            throw new PaneException("Invalid status code: $status");
        }
        $this->status = $status;
        return $this;
    }

}
