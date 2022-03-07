<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Mailer\Bridge\Amazon\Transport;

use AsyncAws\Ses\Input\SendEmailRequest;
use AsyncAws\Ses\ValueObject\Content;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;

/**
 * @author Jérémy Derussé <jeremy@derusse.com>
 */
class SesApiAsyncAwsTransport extends SesHttpAsyncAwsTransport
{
    public function __toString(): string
    {
        $configuration = $this->sesClient->getConfiguration();
        if (!$configuration->isDefault('endpoint')) {
            $endpoint = parse_url($configuration->get('endpoint'));
            $host = $endpoint['host'].($endpoint['port'] ?? null ? ':'.$endpoint['port'] : '');
        } else {
            $host = $configuration->get('region');
        }

        return sprintf('ses+api://%s@%s', $configuration->get('accessKeyId'), $host);
    }

    protected function getRequest(SentMessage $message): SendEmailRequest
    {
        try {
            $email = MessageConverter::toEmail($message->getOriginalMessage());
        } catch (\Exception $e) {
            throw new RuntimeException(sprintf('Unable to send message with the "%s" transport: ', __CLASS__).$e->getMessage(), 0, $e);
        }

        if ($email->getAttachments()) {
            return parent::getRequest($message);
        }

        $envelope = $message->getEnvelope();

        $request = [
            'FromEmailAddress' => $this->stringifyAddress($envelope->getSender()),
            'Destination' => [
                'ToAddresses' => $this->stringifyAddresses($this->getRecipients($email, $envelope)),
            ],
            'Content' => [
                'Simple' => [
                    'Subject' => [
                        'Data' => $email->getSubject(),
                        'Charset' => 'utf-8',
                    ],
                    'Body' => [],
                ],
            ],
        ];

        if ($emails = $email->getCc()) {
            $request['Destination']['CcAddresses'] = $this->stringifyAddresses($emails);
        }
        if ($emails = $email->getBcc()) {
            $request['Destination']['BccAddresses'] = $this->stringifyAddresses($emails);
        }
        if ($email->getTextBody()) {
            $request['Content']['Simple']['Body']['Text'] = new Content([
                'Data' => $email->getTextBody(),
                'Charset' => $email->getTextCharset(),
            ]);
        }
        if ($email->getHtmlBody()) {
            $request['Content']['Simple']['Body']['Html'] = new Content([
                'Data' => $email->getHtmlBody(),
                'Charset' => $email->getHtmlCharset(),
            ]);
        }
        if ($emails = $email->getReplyTo()) {
            $request['ReplyToAddresses'] = $this->stringifyAddresses($emails);
        }
        if ($header = $email->getHeaders()->get('X-SES-CONFIGURATION-SET')) {
            $request['ConfigurationSetName'] = $header->getBodyAsString();
        }
        if ($header = $email->getHeaders()->get('X-SES-SOURCE-ARN')) {
            $request['FromEmailAddressIdentityArn'] = $header->getBodyAsString();
        }
        if ($email->getReturnPath()) {
            $request['FeedbackForwardingEmailAddress'] = $email->getReturnPath()->toString();
        }

        return new SendEmailRequest($request);
    }

    private function getRecipients(Email $email, Envelope $envelope): array
    {
        $emailRecipients = array_merge($email->getCc(), $email->getBcc());

        return array_filter($envelope->getRecipients(), function (Address $address) use ($emailRecipients) {
            return !\in_array($address, $emailRecipients, true);
        });
    }

    protected function stringifyAddresses(array $addresses): array
    {
        return array_map(function (Address $a) {
            return $this->stringifyAddress($a);
        }, $addresses);
    }

    protected function stringifyAddress(Address $a) {
        // AWS does not support UTF-8 address
        if (preg_match('~[\x00-\x08\x10-\x19\x7F-\xFF\r\n]~', $name = $a->getName())) {
            return sprintf('=?UTF-8?B?%s?= <%s>',
                base64_encode($name),
                $a->getEncodedAddress()
            );
        }

        return $a->toString();
    }
}
