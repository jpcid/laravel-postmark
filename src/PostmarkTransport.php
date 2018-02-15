<?php

namespace Coconuts\Mail;

use Swift_MimePart;
use Swift_Attachment;
use Swift_Mime_SimpleMessage;
use GuzzleHttp\ClientInterface;
use Illuminate\Mail\Transport\Transport;

class PostmarkTransport extends Transport
{
    /**
     * Guzzle client instance.
     *
     * @var \GuzzleHttp\ClientInterface
     */
    protected $client;

    /**
     * The Postmark API key.
     *
     * @var string
     */
    protected $key;

    /**
     * The Postmark API end-point.
     *
     * @var string
     */
    protected $url = 'https://api.postmarkapp.com/email';

    /**
     * Create a new Postmark transport instance.
     *
     * @param \GuzzleHttp\ClientInterface $client
     * @param string $key
     *
     * @return void
     */
    public function __construct(ClientInterface $client, $key)
    {
        $this->key = $key;
        $this->client = $client;
    }

    /**
     * {@inheritdoc}
     */
    public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null)
    {
        $this->beforeSendPerformed($message);

        $response = $this->client->post($this->url, $this->payload($message));

        $message->getHeaders()->addTextHeader(
            'X-PM-Message-Id',
            $this->getMessageId($response)
        );

        $this->sendPerformed($message);

        return $this->numberOfRecipients($message);
    }

    /**
     * Get all attachments for the given message.
     *
     * @param \Swift_Mime_SimpleMessage $message
     *
     * @return array
     */
    protected function getAttachments(Swift_Mime_SimpleMessage $message)
    {
        return collect($message->getChildren())
            ->filter(function ($child) {
                return $child instanceof Swift_Attachment;
            })
            ->map(function ($child) {
                return [
                    'Name' => $child->getHeaders()->get('content-type')->getParameter('name'),
                    'Content' => base64_encode($child->getBody()),
                    'ContentType' => $child->getContentType(),
                ];
            })
            ->values()
            ->toArray();
    }

    /**
     * Format the display name.
     *
     * @param  string
     * @return string
     */
    protected function getDisplayname($value)
    {
        if (strpos($value, ',') !== false) {
            return '"' . $value . '"';
        }

        return $value;
    }

    /**
     * Format the contacts for the API request.
     *
     * @param string|array $contacts
     *
     * @return string
     */
    protected function getContacts($contacts)
    {
        return collect($contacts)
            ->map(function ($display, $address) {
                return $display ? $this->getDisplayname($display) . " <{$address}>" : $address;
            })
            ->values()
            ->implode(',');
    }

    /**
     * Get the message ID from the response.
     *
     * @param \GuzzleHttp\Psr7\Response $response
     *
     * @return string
     */
    protected function getMessageId($response)
    {
        return object_get(
            json_decode($response->getBody()->getContents()),
            'MessageID'
        );
    }

    /**
     * Get the body for the given message.
     *
     * @param \Swift_Mime_SimpleMessage $message
     *
     * @return string
     */
    protected function getBody(Swift_Mime_SimpleMessage $message)
    {
        return $message->getBody() ?: '';
    }

    /**
     * Get the text and html fields for the given message.
     *
     * @param \Swift_Mime_SimpleMessage $message
     *
     * @return array
     */
    protected function getHtmlAndTextBody(Swift_Mime_SimpleMessage $message)
    {
        $data = [];

        switch ($message->getContentType()) {
            case 'text/html':
            case 'multipart/mixed':
            case 'multipart/related':
            case 'multipart/alternative':
                $data['HtmlBody'] = $this->getBody($message);
                break;
            default:
                $data['TextBody'] = $this->getBody($message);
                break;
        }

        if ($text = $this->getMimePart($message, 'text/plain')) {
            $data['TextBody'] = $text->getBody();
        }

        if ($html = $this->getMimePart($message, 'text/html')) {
            $data['HtmlBody'] = $html->getBody();
        }

        if ($message->getChildren()) {
            $data['Attachments'] = array();
            foreach ($message->getChildren() as $attachment) {
                if (is_object($attachment) and $attachment instanceof \Swift_Mime_Attachment) {
                    $a = array(
                        'Name' => $attachment->getFilename(),
                        'Content' => base64_encode($attachment->getBody()),
                        'ContentType' => $attachment->getContentType()
                    );
                    if($attachment->getDisposition() != 'attachment' && $attachment->getId() != NULL) {
                        $a['ContentID'] = 'cid:'.$attachment->getId();
                    }
                    $data['Attachments'][] = $a;
                }
            }
        }

        return $data;
    }

    /**
     * Get a mime part from the given message.
     *
     * @param \Swift_Mime_SimpleMessage $message
     * @param string $mimeType
     *
     * @return \Swift_MimePart
     */
    protected function getMimePart(Swift_Mime_SimpleMessage $message, $mimeType)
    {
        return collect($message->getChildren())
            ->filter(function ($child) {
                return $child instanceof Swift_MimePart;
            })
            ->filter(function ($child) use ($mimeType) {
                return strpos($child->getContentType(), $mimeType) === 0;
            })
            ->first();
    }

    /**
     * Get the subject for the given message.
     *
     * @param \Swift_Mime_SimpleMessage $message
     *
     * @return string
     */
    protected function getSubject(Swift_Mime_SimpleMessage $message)
    {
        return $message->getSubject() ?: '';
    }

    /**
     * Get the tag for the given message.
     *
     * @param \Swift_Mime_SimpleMessage $message
     *
     * @return string
     */
    protected function getTag(Swift_Mime_SimpleMessage $message)
    {
        return optional(
            collect($message->getHeaders()->getAll('tag'))
            ->last()
        )
        ->getFieldBody() ?: '';
    }

    /**
     * Get the HTTP payload for sending the Postmark message.
     *
     * @param \Swift_Mime_SimpleMessage $message
     *
     * @return array
     */
    protected function payload(Swift_Mime_SimpleMessage $message)
    {
        return collect([
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-Postmark-Server-Token' => $this->key,
            ]
        ])
        ->merge([
            'json' => collect([
                'Cc' => $this->getContacts($message->getCc()),
                'Bcc' => $this->getContacts($message->getBcc()),
                'Tag' => $this->getTag($message),
                'Subject' => $this->getSubject($message),
                'ReplyTo' => $this->getContacts($message->getReplyTo()),
                'Attachments' => $this->getAttachments($message),
            ])
            ->reject(function ($item) {
                return empty($item);
            })
            ->put('From', $this->getContacts($message->getFrom()))
            ->put('To', $this->getContacts($message->getTo()))
            ->merge($this->getHtmlAndTextBody($message))
        ])
        ->toArray();
    }
}
