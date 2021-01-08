<?php
declare(strict_types = 1);

/*
 * This file is part of the package t3g/blog.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace T3G\AgencyPack\Blog\Mail;

use Symfony\Component\Mime\Email;
use TYPO3\CMS\Core\Mail\MailMessage as CoreMailMessage;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class MailMessage
{
    /**
     * @var CoreMailMessage
     */
    protected $mailMessage;

    /**
     * @var string
     */
    protected $subject;

    /**
     * @var string
     */
    protected $body;

    /**
     * @var array<int|string, string>
     */
    protected $from;

    /**
     * @var array<int|string, string>
     */
    protected $to;

    public function __construct()
    {
        /**
         * @var CoreMailMessage $mailMessage;
         */
        $mailMessage = GeneralUtility::makeInstance(CoreMailMessage::class);
        $this->mailMessage = $mailMessage;
    }

    public function setSubject(string $subject): self
    {
        $this->subject = $subject;
        return $this;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function setBody(string $body): self
    {
        $this->body = $body;
        return $this;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * @param array<int|string, string> $from
     */
    public function setFrom(array $from): self
    {
        $this->from = $from;
        return $this;
    }

    /**
     * @return array<int|string, string>
     */
    public function getFrom(): array
    {
        return $this->from;
    }

    /**
     * @param array<int|string, string> $to
     */
    public function setTo(array $to): self
    {
        $this->to = $to;
        return $this;
    }

    /**
     * @return array<int|string, string>
     */
    public function getTo(): array
    {
        return $this->to;
    }

    public function send(): bool
    {
        $this->mailMessage->setSubject($this->getSubject());
        $this->mailMessage->setFrom($this->getFrom());
        $this->mailMessage->setTo($this->getTo());

        if ($this->mailMessage instanceof Email) {
            $this->mailMessage->html($this->getBody());
        } else {
            $this->mailMessage->setBody($this->getBody(), 'text/html');
        }

        return (bool) $this->mailMessage->send();
    }
}
