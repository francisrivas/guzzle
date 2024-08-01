private function applyOptions(RequestInterface $request, array &$options): RequestInterface
{
    $modify = [
        'set_headers' => [],
    ];

    $modify = $this->handleHeaders($options, $modify);
    $modify = $this->handleBody($options, $modify);
    $modify = $this->handleAuth($options, $modify);
    $modify = $this->handleQuery($options, $modify);
    $this->validateSink($options);

    if (isset($options['version'])) {
        $modify['version'] = $options['version'];
    }

    $request = Psr7\Utils::modifyRequest($request, $modify);
    $request = $this->applyConditionalHeaders($request, $options);

    return $request;
}

private function handleHeaders(array &$options, array $modify): array
{
    if (isset($options['headers'])) {
        if (array_keys($options['headers']) === range(0, count($options['headers']) - 1)) {
            throw new InvalidArgumentException('The headers array must have header name as keys.');
        }
        $modify['set_headers'] = $options['headers'];
        unset($options['headers']);
    }
    return $modify;
}

private function handleBody(array &$options, array $modify): array
{
    if (isset($options['form_params'])) {
        if (isset($options['multipart'])) {
            throw new InvalidArgumentException('You cannot use form_params and multipart at the same time.');
        }
        $options['body'] = http_build_query($options['form_params'], '', '&');
        unset($options['form_params']);
        $modify['_conditional']['Content-Type'] = 'application/x-www-form-urlencoded';
    }

    if (isset($options['multipart'])) {
        $options['body'] = new Psr7\MultipartStream($options['multipart']);
        unset($options['multipart']);
    }

    if (isset($options['json'])) {
        $options['body'] = Utils::jsonEncode($options['json']);
        unset($options['json']);
        $modify['_conditional']['Content-Type'] = 'application/json';
    }

    if (isset($options['body'])) {
        if (is_array($options['body'])) {
            throw $this->invalidBody();
        }
        $modify['body'] = Psr7\Utils::streamFor($options['body']);
        unset($options['body']);
    }

    return $modify;
}

private function handleAuth(array &$options, array $modify): array
{
    if (!empty($options['auth']) && is_array($options['auth'])) {
        $value = $options['auth'];
        $type = isset($value[2]) ? strtolower($value[2]) : 'basic';
        switch ($type) {
            case 'basic':
                $modify['set_headers']['Authorization'] = 'Basic ' . base64_encode("$value[0]:$value[1]");
                break;
            case 'digest':
                $options['curl'][CURLOPT_HTTPAUTH] = CURLAUTH_DIGEST;
                $options['curl'][CURLOPT_USERPWD] = "$value[0]:$value[1]";
                break;
            case 'ntlm':
                $options['curl'][CURLOPT_HTTPAUTH] = CURLAUTH_NTLM;
                $options['curl'][CURLOPT_USERPWD] = "$value[0]:$value[1]";
                break;
        }
    }
    return $modify;
}

private function handleQuery(array &$options, array $modify): array
{
    if (isset($options['query'])) {
        $value = $options['query'];
        if (is_array($value)) {
            $value = http_build_query($value, '', '&', PHP_QUERY_RFC3986);
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('query must be a string or array');
        }
        $modify['query'] = $value;
        unset($options['query']);
    }
    return $modify;
}

private function validateSink(array &$options): void
{
    if (isset($options['sink']) && is_bool($options['sink'])) {
        throw new InvalidArgumentException('sink must not be a boolean');
    }
}

private function applyConditionalHeaders(RequestInterface $request, array &$options): RequestInterface
{
    if (isset($options['_conditional'])) {
        $modify = [];
        foreach ($options['_conditional'] as $k => $v) {
            if (!$request->hasHeader($k)) {
                $modify['set_headers'][$k] = $v;
            }
        }
        $request = Psr7\Utils::modifyRequest($request, $modify);
        unset($options['_conditional']);
    }

    if ($request->getBody() instanceof Psr7\MultipartStream) {
        $options['_conditional']['Content-Type'] = 'multipart/form-data; boundary='
            . $request->getBody()->getBoundary();
    }

    return $request;
}
