<?php
declare(strict_types=1);

/**
 * Simple profiler for tracking execution time of different code sections
 */
class Profiler
{
    private array $timing = [];
    private array $names = [];

    /**
     * Record a timing checkpoint with a descriptive label
     *
     * @param string $label Description of this checkpoint
     */
    public function flag(string $label): void
    {
        $this->timing[] = microtime(true);
        $this->names[] = $label;
    }

    /**
     * Generate HTML output of all timing checkpoints
     *
     * @return string HTML formatted timing report
     */
    public function getReport(): string
    {
        $size = count($this->timing);
        if ($size === 0) {
            return '';
        }

        $output = '';
        for ($i = 0; $i < $size - 1; $i++) {
            $elapsed = $this->timing[$i + 1] - $this->timing[$i];
            $output .= "<b>{$this->names[$i]}</b><br>";
            $output .= sprintf("&nbsp;&nbsp;&nbsp;%f<br>", $elapsed);
        }
        $output .= "<b>{$this->names[$size - 1]}</b><br>";
        $total = $this->timing[$size - 1] - $this->timing[0];
        $output .= "<b>Total time:</b> " . $total;

        return $output;
    }

    /**
     * Reset all profiling data
     */
    public function reset(): void
    {
        $this->timing = [];
        $this->names = [];
    }
}
