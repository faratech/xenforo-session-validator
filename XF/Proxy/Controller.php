<?php

namespace WindowsForum\SessionValidator\XF\Proxy;

use XF\Entity\ImageProxy;
use XF\Http\Response;
use XF\Repository\ImageProxyRepository;

class Controller extends XFCP_Controller
{
    protected function outputImageErrorResponse($url, $error)
    {
        if ($error !== self::ERROR_FAILED)
        {
            return parent::outputImageErrorResponse($url, $error);
        }

        $proxyRepo = $this->app->repository(ImageProxyRepository::class);
        $image = $proxyRepo->getPlaceholderImage();

        $response = $this->app->response();
        $this->applyImageResponseHeaders($response, $image, $error);
        $response->httpCode(200);
        $response->header('Cache-Control', 'public, max-age=3600');
        $response->header('X-Robots-Tag', 'noindex, noimageindex, nofollow');
        $response->body($response->responseFile($image->getPlaceholderPath()));

        return $response;
    }

    public function applyImageResponseHeaders(Response $response, ImageProxy $image, $error)
    {
        parent::applyImageResponseHeaders($response, $image, $error);

        if (!$error && $this->isInlineIconMimeType($image->mime_type))
        {
            $response->contentType($image->mime_type, '')
                ->setDownloadFileName($image->file_name ?: 'favicon.ico', true);
            $response->header('X-Content-Type-Options', 'nosniff');
        }

        if (!$error && $image->mime_type === 'image/svg+xml')
        {
            $response->contentType('image/svg+xml', '')
                ->setDownloadFileName($image->file_name ?: 'image.svg', true);
            $response->header('Content-Security-Policy', $this->getSvgContentSecurityPolicy());
            $response->header('X-Content-Type-Options', 'nosniff');
        }
    }

    protected function isInlineIconMimeType($mimeType): bool
    {
        return in_array($mimeType, [
            'image/x-icon',
            'image/vnd.microsoft.icon',
        ], true);
    }

    protected function getSvgContentSecurityPolicy(): string
    {
        return "default-src 'none'; img-src data:; style-src 'unsafe-inline'; script-src 'none'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'";
    }
}
