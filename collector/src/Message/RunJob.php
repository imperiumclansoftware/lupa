<?php

namespace App\Message;

final readonly class RunJob implements AsyncMessage
{
    public function __construct(public int $jobId)
    {
    }
}
