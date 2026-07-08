<?php

namespace WindowsForum\SessionValidator\XF\Service;

use enshrined\svgSanitize\Sanitizer;
use XF\Entity\ImageProxy;
use XF\Util\File;

class ImageProxyService extends XFCP_ImageProxyService
{
    protected function fetchImageDataFromUrl($url)
    {
        $url = $this->proxyRepo->cleanUrlForFetch($url);
        if (!preg_match('#^https?://#i', $url))
        {
            throw new \InvalidArgumentException("URL must be http or https");
        }

        $urlParts = @parse_url($url);

        $validImage = false;
        $fileName = !empty($urlParts['path']) ? basename($urlParts['path']) : null;
        $mimeType = null;
        $error = null;
        $streamFile = File::getTempDir() . '/' . strtr(md5($url) . '-' . uniqid(), '/\\.', '---') . '.temp';
        $imageProxyMaxSize = $this->app->options()->imageProxyMaxSize * 1024;

        try
        {
            $response = $this->app->http()->reader()->getUntrusted($url, [
                'time' => 3,
                'bytes' => $imageProxyMaxSize ?: -1,
            ], $streamFile, [
                'connect_timeout' => 1,
                'timeout' => 4,
                'headers' => [
                    'Accept' => 'image/svg+xml,image/*,*/*;q=0.8',
                ],
            ], $error);
        }
        catch (\Exception $e)
        {
            $response = null;
            $error = $e->getMessage();
        }

        if ($response)
        {
            $response->getBody()->close();

            if ($response->getStatusCode() == 200)
            {
                $disposition = $response->getHeader('Content-Disposition');
                if (!empty($disposition) && preg_match('/filename=(\'|"|)(.+)\\1/siU', $disposition[0], $match))
                {
                    $fileName = $match[2];
                }
                if (!$fileName)
                {
                    $fileName = 'image';
                }

                $contentType = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0] ?? ''));
                $extension = strtolower(File::getFileExtension($fileName));
                $contents = filesize($streamFile) ? file_get_contents($streamFile) : '';
                $trimmed = is_string($contents) ? ltrim($contents) : '';
                $isSvg = (
                    $contentType === 'image/svg+xml'
                    || $extension === 'svg'
                    || strpos($trimmed, '<svg') === 0
                    || strpos($trimmed, '<?xml') === 0
                );

                if ($isSvg)
                {
                    $sanitizer = new Sanitizer();
                    $sanitizer->removeRemoteReferences(true);
                    $sanitized = $sanitizer->sanitize((string)$contents);

                    if ($sanitized !== false && stripos($sanitized, '<svg') !== false)
                    {
                        file_put_contents($streamFile, $sanitized);
                        $mimeType = 'image/svg+xml';
                        $fileName = $this->normalizeSvgFileName($fileName);
                        $validImage = true;
                    }
                    else
                    {
                        $error = \XF::phraseDeferred('could_not_upload_svg_asset_after_sanitization');
                    }
                }
                else
                {
                    $imageInfo = filesize($streamFile) ? @getimagesize($streamFile) : false;
                    if ($imageInfo)
                    {
                        $imageType = $imageInfo[2];

                        $extensionMap = [
                            IMAGETYPE_GIF => ['gif'],
                            IMAGETYPE_JPEG => ['jpg', 'jpeg', 'jpe'],
                            IMAGETYPE_PNG => ['png'],
                            IMAGETYPE_ICO => ['ico'],
                        ];

                        if (defined('IMAGETYPE_WEBP'))
                        {
                            $extensionMap[IMAGETYPE_WEBP] = ['webp'];
                        }

                        if (isset($extensionMap[$imageType]))
                        {
                            $mimeType = $imageInfo['mime'];

                            $validExtensions = $extensionMap[$imageType];
                            if (!in_array($extension, $validExtensions))
                            {
                                $extensionStart = strrpos($fileName, '.');
                                $fileName = ($extensionStart ? substr($fileName, 0, $extensionStart) : $fileName)
                                    . '.'
                                    . $validExtensions[0];
                            }

                            $validImage = true;
                        }
                        else
                        {
                            $error = \XF::phraseDeferred('image_is_invalid_type');
                        }
                    }
                    else
                    {
                        $error = \XF::phraseDeferred('file_not_an_image');
                    }
                }
            }
            else
            {
                $error = \XF::phraseDeferred('received_unexpected_response_code_x_message_y', [
                    'code' => $response->getStatusCode(),
                    'message' => $response->getReasonPhrase(),
                ]);
            }
        }

        if (!$validImage)
        {
            @unlink($streamFile);
        }

        return [
            'valid' => $validImage,
            'error' => $error,
            'dataFile' => $validImage ? $streamFile : null,
            'fileName' => $fileName,
            'mimeType' => $mimeType,
        ];
    }

    protected function finalizeFromFetchResults(ImageProxy $image, array $fetchResults)
    {
        if (($fetchResults['mimeType'] ?? null) !== 'image/svg+xml')
        {
            parent::finalizeFromFetchResults($image, $fetchResults);
            return;
        }

        $image->is_processing = 0;

        if ($fetchResults['valid'])
        {
            $fileHash = md5_file($fetchResults['dataFile']);
            if (!$image->pruned && $image->file_hash === $fileHash)
            {
                $saved = true;
            }
            else
            {
                $saved = File::copyFileToAbstractedPath(
                    $fetchResults['dataFile'],
                    $image->getAbstractedImagePath()
                );
            }

            if ($saved)
            {
                $image->fetch_date = time();
                $image->file_name = $fetchResults['fileName'];
                $image->file_size = filesize($fetchResults['dataFile']);
                $image->file_hash = $fileHash;
                $image->mime_type = 'image/svg+xml';
                $image->pruned = false;
                $image->failed_date = 0;
                $image->fail_count = 0;
                $image->file_metadata = $this->getSvgMetadata((string)file_get_contents($fetchResults['dataFile']));
            }
            else
            {
                $image->pruned = true;
                $image->file_hash = '';
            }

            @unlink($fetchResults['dataFile']);
        }
        else
        {
            $image->failed_date = time();
            $image->fail_count++;
        }

        $image->save();
    }

    protected function normalizeSvgFileName(string $fileName): string
    {
        $extension = strtolower(File::getFileExtension($fileName));
        if ($extension === 'svg')
        {
            return $fileName;
        }

        $extensionStart = strrpos($fileName, '.');
        return ($extensionStart ? substr($fileName, 0, $extensionStart) : $fileName) . '.svg';
    }

    protected function getSvgMetadata(string $svg): array
    {
        $metadata = ['width' => 0, 'height' => 0];

        if (preg_match('/<svg\b[^>]*\bwidth=(["\'])([0-9.]+)(?:px)?\1/i', $svg, $match))
        {
            $metadata['width'] = (int)round((float)$match[2]);
        }
        if (preg_match('/<svg\b[^>]*\bheight=(["\'])([0-9.]+)(?:px)?\1/i', $svg, $match))
        {
            $metadata['height'] = (int)round((float)$match[2]);
        }
        if ((!$metadata['width'] || !$metadata['height'])
            && preg_match('/<svg\b[^>]*\bviewBox=(["\'])\s*[-0-9.]+\s+[-0-9.]+\s+([0-9.]+)\s+([0-9.]+)\s*\1/i', $svg, $match)
        )
        {
            $metadata['width'] = $metadata['width'] ?: (int)round((float)$match[2]);
            $metadata['height'] = $metadata['height'] ?: (int)round((float)$match[3]);
        }

        return $metadata;
    }
}
