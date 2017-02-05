<?php

/**
 * Created by PhpStorm.
 * User: nikita.kem
 * Date: 2/4/17
 * Time: 7:32 PM
 */

namespace PHPPM\Log;

class LineFormatter extends \Monolog\Formatter\LineFormatter
{
    /**
     * @var null|string
     */
    private $pid;

    public function __construct($pid, $format, $dateFormat, $allowInlineLineBreaks, $ignoreEmptyContextAndExtra)
    {
        $this->pid = $pid;

        parent::__construct($format, $dateFormat, $allowInlineLineBreaks, $ignoreEmptyContextAndExtra);
    }

    /**
     * {@inheritdoc}
     */
    public function format(array $record)
    {
        $out = parent::format($record);

        return preg_replace('/%pid/', $this->pid, $out);
    }
}
