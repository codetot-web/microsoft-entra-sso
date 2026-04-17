<?php

namespace LiteSaml;

class Schema
{
    private const PATH = __DIR__ . '/../resources/';

    public static function validate(string $xml, string $schema): ErrorBag
    {
        $errorBag = new ErrorBag();

        $schemaPath = self::PATH . $schema;

        if (! is_file($schemaPath)) {
            throw new UnexpectedSchemaException('Invalid schema specified: ' . $schema);
        }

        set_error_handler(function ($errno, $errstr) use ($errorBag) {
            $errorBag->addError(
                level: LIBXML_ERR_FATAL,
                code: $errno,
                message: $errstr
            );
        });

        if ($xml === '') {
            restore_error_handler();

            $errorBag->addError(LIBXML_ERR_FATAL, 0, 'XML must not be empty');

            return $errorBag;
        }

        libxml_clear_errors();

        $doc = new \DOMDocument();

        if (! @$doc->loadXML($xml)) {
            restore_error_handler();

            $errorBag->addError(LIBXML_ERR_FATAL, 0, 'Invalid XML');

            return $errorBag;
        }

        @$doc->schemaValidate($schemaPath);

        $errors = libxml_get_errors();

        foreach ($errors as $error) {
            $errorBag->addError(
                level: $error->level,
                code: $error->code,
                message: $error->message,
                line: $error->line,
                column: $error->column,
            );
        }

        restore_error_handler();

        return $errorBag;
    }
}
