<?php

namespace LaravelMandrill;

use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use MailchimpTransactional\ApiClient;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;


class MandrillTransport extends AbstractTransport
{
    /**
     * Create a new Mandrill transport instance.
     *
     * @param ApiClient $mailchimp
     * @param array $headers
     */
    public function __construct(
        protected ApiClient $mailchimp,
        protected array $headers,
        protected ?array $template = null)
    {
        parent::__construct();
    }

    /**
     * {@inheritDoc}
     */
    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        // Set headers must take place before SentMessage is formed or it will not be part
        // of the payload submitted to the Mandrill API.
        $message = $this->setHeaders($message);
        
        return parent::send($message, $envelope);
    }

    /**
     * {@inheritDoc}
     */
    protected function doSend(SentMessage $message): void
    {
        if (isset($this->template))
        {
            $data = $this->sendTemplate($message);
        }
        else
        {
            $data = $this->sendRaw($message);
        }

        // If Mandrill _id was returned, set it as the message id for
        // use elsewhere in the application.
        if (!empty($data[0]?->_id)) {
            $messageId = $data[0]->_id;
            $message->setMessageId($messageId);
            // Convention seems to be to set this header on the original for access later.
            $message->getOriginalMessage()->getHeaders()->addHeader('X-Message-ID', $messageId);
        }
    }

    /**
     * Send the message using the Mandrill API /messages/send-raw endpoint.
     * 
     * @param SentMessage $message
     * @throws \LaravelMandrill\MandrillTransportException
     * @return mixed
     */
    private function sendRaw(SentMessage $message): mixed
    {
        $data = $this->mailchimp->messages->sendRaw([
            'raw_message' => $message->toString(),
            'async' => true,
            'to' => $this->getTo($message),
        ]);

        if ($data instanceof \GuzzleHttp\Exception\RequestException)
        {
            throw new MandrillTransportException($data->getMessage(), $data->getCode(), $data);
        }

        return $data;
    }

    /**
     * Send the message using the Mandrill API /messages/send-template endpoint.
     * 
     * @param \Symfony\Component\Mailer\SentMessage $message
     * @throws \RuntimeException
     * @throws \LaravelMandrill\MandrillTransportException
     * @return mixed
     */
    private function sendTemplate(SentMessage $message): mixed
    {
        $originalMessage = $message->getOriginalMessage();

        if (!$originalMessage instanceof Email)
        {
            throw new \RuntimeException('Original message is not an instance of Email');
        }

        $data = $this->mailchimp->messages->sendTemplate([
            'template_name' => $this->template['template_name'],
            'template_content' => [
                (object)[
                    'name' => $this->template['template_content'] ?? 'main',
                    'content' => $originalMessage->getHtmlBody(),
                ]
            ],
            'async' => true,
            'message' => [
                'from_email' => $originalMessage->getFrom()[0]->getAddress(),
                'from_name' => $originalMessage->getReplyTo()[0]->getName(),
                'subject' => $originalMessage->getSubject(),
                'to' => $this->getToFull($message),
                'attachments' => $this->getAttachments($originalMessage),

                'headers' => [
                    'Reply-To' => $originalMessage->getReplyTo()[0]->getAddress(),
                ],

                'global_merge_vars' => $this->getMergeVars($originalMessage, 'X-Global-Merge-Vars'),
                'merge_vars' => $this->getMergeVars($originalMessage),

                'tags' => $this->getTags($originalMessage),
            ]
        ]);

        if ($data instanceof \GuzzleHttp\Exception\RequestException)
        {
            throw new MandrillTransportException($data->getMessage(), $data->getCode(), $data);
        }

        return $data;
    }

    /**
     * Retrieves recipients from the original message or envelope.
     *
     * @param SentMessage $message
     * @return array
     */
    protected function getTo(SentMessage $message): array
    {
        $recipients = [];

        $originalMessage = $message->getOriginalMessage();

        if ($originalMessage instanceof Email) {

            if (!empty($originalMessage->getTo())) {
                foreach ($originalMessage->getTo() as $to) {
                    $recipients[] = $to->getEncodedAddress();
                }
            }

            if (!empty($originalMessage->getCc())) {
                foreach ($originalMessage->getCc() as $cc) {
                    $recipients[] = $cc->getEncodedAddress();
                }
            }

            if (!empty($originalMessage->getBcc())) {
                foreach ($originalMessage->getBcc() as $bcc) {
                    $recipients[] = $bcc->getEncodedAddress();
                }
            }
        }

        // Fall-back to envelope recipients
        if (empty($recipients)) {
            foreach ($message->getEnvelope()->getRecipients() as $recipient) {
                $recipients[] = $recipient->getEncodedAddress();
            }
        }

        return $recipients;
    }

    /**
     * Retrieves recipients from the original message or envelope.
     *
     * @param SentMessage $message
     * @return array
     */
    protected function getToFull(SentMessage $message): array
    {
        $recipients = [];

        $originalMessage = $message->getOriginalMessage();

        if ($originalMessage instanceof Email) {
            if (!empty($originalMessage->getTo())) {
                foreach ($originalMessage->getTo() as $to) {
                    $recipients[] = [
                        'email' => $to->getEncodedAddress(),
                        'name' => $to->getName(),
                        'type' => 'to',
                    ];
                }
            }

            if (!empty($originalMessage->getCc())) {
                foreach ($originalMessage->getCc() as $cc) {
                    $recipients[] = [
                        'email'=> $cc->getEncodedAddress(),
                        'name'=> $cc->getName(),
                        'type'=> 'cc',
                    ];
                }
            }

            if (!empty($originalMessage->getBcc())) {
                foreach ($originalMessage->getBcc() as $bcc) {
                    $recipients[] = [
                        'email'=> $bcc->getEncodedAddress(),
                        'name'=> $bcc->getName(),
                        'type'=> 'bcc',
                    ];
                }
            }
        }

        // Fall-back to envelope recipients
        if (empty($recipients)) {
            foreach ($message->getEnvelope()->getRecipients() as $recipient) {
                $recipients[] = [
                    'email'=> $recipient->getEncodedAddress(),
                    'name'=> $recipient->getName(),
                    'type'=> 'to',
                ];
            }
        }

        return $recipients;
    }

    /**
     * Retrieves attachments from the original message.
     *
     * @param SentMessage $message
     * @return array
     */
    protected function getAttachments(Message $originalMessage): array
    {
        $attachments = [];

        if ($originalMessage instanceof Email) {
            foreach ($originalMessage->getAttachments() as $attachment) {
                $attachments[] = [
                    'type' => $attachment->getMediaType(),
                    'name' => $attachment->getName(),
                    'content' => base64_encode($attachment->getBody()),
                ];
            }
        }

        return $attachments;
    }

    protected function getMergeVars(Message $originalMessage, string $varsHeader = 'X-Merge-Vars'): array
    {
        $headers = $originalMessage->getHeaders();
        $varsHeader = $headers->get($varsHeader);

        $vars = [];

        if ($varsHeader) {
            $decoded = json_decode($varsHeader->getBody(), true);
            if (is_array($decoded)) {
                foreach ($decoded as $name => $content) {
                    $vars[] = [
                        'name' => $name,
                        'content' => $content,
                    ];
                }
            }
        }

        return $vars;
    }

    protected function getTags(Message $originalMessage): array
    {
        $tags = [];

        if ($originalMessage instanceof Email && $originalMessage->getHeaders()->has('X-Tag')) {
            foreach ($originalMessage->getHeaders()->all('X-Tag') as $header) {
                $tags[] = $header->getBodyAsString();
            }
        }

        return $tags;
    }

    /**
     * Set headers of email.
     *
     * @param Message $message
     *
     * @return Message
     */
    protected function setHeaders(Message $message): Message
    {   
        $messageHeaders = $message->getHeaders();
        $messageHeaders->addTextHeader('X-Dump', 'dumpy');

        foreach ($this->headers as $name => $value) {
            $messageHeaders->addTextHeader($name, $value);
        }

        return $message;
    }

    /**
     * Get the string representation of the transport.
     *
     * @return string
     */
    public function __toString(): string
    {
        return 'mandrill';
    }

    /**
     * Replace Mandrill client.
     * This is used primarily for testing but could in theory allow other use cases
     * e.g. Configuring proxying in Guzzle.
     * 
     * @param ApiClient $client [description]
     * @return void
     */
    public function setClient(ApiClient $client): void
    {
        $this->mailchimp = $client;
    }
}
