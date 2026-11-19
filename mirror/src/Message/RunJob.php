<?php

namespace App\Message;

final readonly class RunJob implements AsyncMessage
{
    /** @param string $jobId UUID de la tâche (RFC 4122) */
    public function __construct(public string $jobId)
    {
    }
}
