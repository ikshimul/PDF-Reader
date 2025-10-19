<?php

namespace App\Assistants;

use Carbon\Carbon;
use Illuminate\Support\Str;
use Exception;
use App\GeonamesCountry;

/**
 * Parse a specific transport-order PDF format and produce a structured array
 * matching storage/order_schema.json.
 */
class InzamamPdfAssistant extends PdfClient
{
    /**
     * Validate whether the provided lines match the expected format.
     *
     * @param array<int, string> $lines
     * @return bool
     */
    public static function validateFormat(array $lines): bool
    {
        if (empty($lines)) {
            return false;
        }

        $hasBooking = array_key_first(array_filter($lines, fn ($l) => Str::contains(Str::upper($l), 'BOOKING'))) !== null;
        $hasChartering = array_key_first(array_filter($lines, fn ($l) => Str::contains(Str::upper($l), 'CHARTERING CONFIRMATION'))) !== null;
        $hasShippingPrice = array_key_first(array_filter($lines, fn ($l) => Str::contains(Str::upper($l), 'SHIPPING PRICE') || Str::contains(Str::upper($l), 'RATE'))) !== null;
        $hasLoading = array_key_first(array_filter($lines, fn ($l) => Str::startsWith(Str::upper(trim($l)), 'LOADING') || Str::contains(Str::upper($l), 'LOADING ON:'))) !== null;
        $hasDelivery = array_key_first(array_filter($lines, fn ($l) => Str::startsWith(Str::upper(trim($l)), 'DELIVERY') || Str::contains(Str::upper($l), 'DELIVERY ON:'))) !== null;

        return $hasBooking || $hasChartering || ($hasShippingPrice && $hasLoading && $hasDelivery);
    }

    /**
     * Main processing entrypoint.
     *
     * @param array<int, string> $lines
     * @param string|null $attachmentFilename
     * @return void
     *
     * @throws Exception
     */
    public function processLines(array $lines, ?string $attachmentFilename = null): void
    {
        if (! static::validateFormat($lines)) {
            throw new Exception('Invalid  PDF');
        }

        $orderReference = $this->extractOrderReference($lines);
        [$freightPrice, $freightCurrency] = $this->extractFreight($lines);
        $customer = $this->extractCustomer($lines);
        [$loadingLocations, $destinationLocations] = $this->extractLocations($lines);
        $cargos = $this->extractCargos($lines);

        $attachmentFilenames = [mb_strtolower($attachmentFilename ?? '')];
        $freightPrice = $freightPrice ?? 0;
        $freightCurrency = $freightCurrency ?? 'EUR';
        
        $data = [
            'customer' => $customer,
            'loading_locations' => $loadingLocations,
            'destination_locations' => $destinationLocations,
            'attachment_filenames' => $attachmentFilenames,
            'cargos' => $cargos,
            'order_reference' => $orderReference,
            'freight_price' => $freightPrice,
            'freight_currency' => $freightCurrency,
        ];

        $this->createOrder($data);
    }

    /**
     * Extract customer details.
     *
     * @param array<int, string> $lines
     * @return array<string, mixed>
     */
    protected function extractCustomer(array $lines): array
    {
        $invLine = array_key_first(array_filter($lines, fn ($l) => Str::startsWith(Str::upper(trim($l)), 'INVOICING ADRESS') || Str::contains(Str::upper($l), 'INVOICING ADRESS')));
        if ($invLine !== null) {
            $company = trim($lines[$invLine + 1] ?? '');
            $street1 = trim($lines[$invLine + 2] ?? '');
            $street2 = trim($lines[$invLine + 3] ?? '');
            $cityPostalLine = trim($lines[$invLine + 4] ?? '');
        } else {
            $idx = array_key_first(array_filter($lines, fn ($l) => Str::contains(Str::upper($l), 'TRANSALLIANCE'))) ?? 0;
            $company = trim($lines[$idx] ?? '');
            $street1 = trim($lines[$idx + 1] ?? '');
            $street2 = trim($lines[$idx + 2] ?? '');
            $cityPostalLine = trim($lines[$idx + 3] ?? '');
        }

        $streetAddress = trim(implode(' ', array_filter([$street1, $street2])));

        $parts = $this->parseCityPostalCountry($cityPostalLine);

        $country = $parts['country'] ?? null;
        if (empty($country)) {
            // Try to guess from address or fallback to GB
            $country = GeonamesCountry::getIso($cityPostalLine) ?? 'GB';
        }

        return [
            'side' => 'none',
            'details' => [
                'company' => $company ?: '',
                'street_address' => $streetAddress ?: '',
                'city' => $parts['city'] ?? '',
                'postal_code' => $parts['postal'] ?? '',
                'country' => $country,
            ],
        ];
    }

    /**
     * Extract order reference (REF.: lines).
     *
     * @param array<int, string> $lines
     * @return string|null
     */
    protected function extractOrderReference(array $lines): ?string
    {
        $i = array_key_first(array_filter($lines, fn ($l) => Str::startsWith(Str::upper(trim($l)), 'REF.:') || Str::startsWith(Str::upper(trim($l)), 'REF:')));
        if ($i !== null) {
            $raw = $lines[$i] ?? '';
            // Trim after REF.:
            return trim(Str::after($raw, 'REF.:')) ?: trim(Str::after($raw, 'REF:')) ?: null;
        }

        // fallback: look for "Ziegler Ref" or "Ziegler Ref <number>"
        $i = array_key_first(array_filter($lines, fn ($l) => Str::contains(Str::upper($l), 'ZIEGLER REF') || Str::contains(Str::upper($l), 'ZIEGLER Ref'))) ?? null;
        if ($i !== null) {
            return trim(preg_replace('/[^0-9A-Za-z\-]/', '', $lines[$i]));
        }

        return null;
    }

    /**
     * Extract freight price and currency.
     *
     * @param array<int, string> $lines
     * @return array{0: ?float, 1: ?string}
     */
    protected function extractFreight(array $lines): array
    {
        $priceLineIndex = array_key_first(array_filter($lines, fn ($l) => Str::contains(Str::upper($l), 'SHIPPING PRICE') || Str::contains(Str::upper($l), 'RATE') || Str::contains(Str::upper($l), 'PRICE'))) ?? null;
        if ($priceLineIndex === null) {
            return [null, null];
        }

        $rawAmount = preg_replace('/[^0-9,\.]/', '', $lines[$priceLineIndex + 1] ?? '');
        $price = $rawAmount !== '' ? (float) str_replace(',', '.', $rawAmount) : null;

        $currencyLine = $lines[$priceLineIndex + 2] ?? $lines[$priceLineIndex + 1] ?? '';
        preg_match('/([A-Z]{3}|EUR|GBP|USD)/i', $currencyLine, $m);
        $currency = isset($m[1]) ? strtoupper($m[1]) : null;

        return [$price, $currency];
    }

    /**
     * Extract locations (loading & delivery).
     *
     * @param array<int, string> $lines
     * @return array{0: array<int, mixed>, 1: array<int, mixed>}
     */
    protected function extractLocations(array $lines): array
    {
        // detect start lines for loading/delivery
        $loadingStart = array_key_first(array_filter(
            $lines,
            fn ($l) =>
            Str::contains(Str::upper($l), 'LOADING') ||
            Str::contains(Str::upper($l), 'COLLECTION')
        ));

        $deliveryStart = array_key_first(array_filter(
            $lines,
            fn ($l) =>
            Str::contains(Str::upper($l), 'DELIVERY') ||
            Str::contains(Str::upper($l), 'UNLOADING') ||
            Str::contains(Str::upper($l), 'DESTINATION')
        ));

        $end = count($lines);

        $loadingBlock = [];
        $deliveryBlock = [];

        if ($loadingStart !== null && $deliveryStart !== null && $deliveryStart > $loadingStart) {
            $loadingBlock = array_slice($lines, $loadingStart, $deliveryStart - $loadingStart);
            $deliveryBlock = array_slice($lines, $deliveryStart, $end - $deliveryStart);
        } elseif ($loadingStart !== null) {
            $loadingBlock = array_slice($lines, $loadingStart);
        } elseif ($deliveryStart !== null) {
            $deliveryBlock = array_slice($lines, $deliveryStart);
        }

        $loadingLocations = [];
        $destinationLocations = [];

        // Try to parse all “Collection” occurrences as multiple loading points
        foreach ($this->splitByKeyword($loadingBlock, ['COLLECTION', 'LOADING']) as $block) {
            $parsed = $this->parseBookingLocationBlock($block);
            if ($parsed) {
                $loadingLocations[] = $parsed;
            }
        }

        if (empty($loadingLocations) && !empty($loadingBlock)) {
            $loadingLocations[] = $this->parseBookingLocationBlock($loadingBlock);
        }

        // Delivery / destination section
        if (!empty($deliveryBlock)) {
            $destinationLocations[] = $this->parseBookingLocationBlock($deliveryBlock);
        }

        return [$loadingLocations, $destinationLocations];
    }


    /**
     * Parse a single location block into company_address and time window.
     *
     * @param array<int, string> $block
     * @return array<string, mixed>
     */
    protected function parseLocationBlock(array $block): array
    {
        // Date pattern dd/mm/yy or dd/mm/yyyy
        $dateLine = array_key_first(array_filter($block, fn ($l) => preg_match('/^[0-3]?[0-9]\/[0-1]?[0-9]\/[0-9]{2,4}$/', trim($l)) === 1)) ?? null;
        $date = $dateLine !== null ? trim($block[$dateLine]) : null;

        $onLine = array_key_first(array_filter($block, fn ($l) => Str::startsWith(Str::upper(trim($l)), 'ON:') || Str::contains(Str::upper($l), 'ON:'))) ?? null;

        // company and address offsets (best-effort)
        $company = $onLine !== null ? trim($block[$onLine + 2] ?? '') : trim($block[$onLine + 1] ?? '');
        $street = $onLine !== null ? trim($block[$onLine + 3] ?? '') : trim($block[$onLine + 2] ?? '');
        $cityPostalLine = $onLine !== null ? trim($block[$onLine + 4] ?? '') : trim($block[$onLine + 3] ?? '');

        $companyAddress = $this->parseCompanyAddress($company, $street, $cityPostalLine);

        $timeLine = array_key_first(array_filter($block, fn ($l) => preg_match('/[0-9]{1,2}h[0-9]{2}\s*-\s*[0-9]{1,2}h[0-9]{2}/i', $l) || preg_match('/\b\d{1,2}:\d{2}\b\s*-\s*\d{1,2}:\d{2}/', $l))) ?? null;
        $timeWindow = $timeLine !== null ? trim($block[$timeLine]) : null;

        $time = $this->parseDateAndTime($date, $timeWindow);

        return [
            'company_address' => $companyAddress,
            'time' => $time,
        ];
    }

    /**
     * Normalize company/address fields.
     *
     * @param string $company
     * @param string $street
     * @param string $cityPostal
     * @return array<string, mixed>
     */
    protected function parseCompanyAddress(string $company, string $street, string $cityPostal): array
    {
        $parts = $this->parseCityPostalCountry($cityPostal);

        $address = [
            'company' => $company,
            'title' => $company,
            'street_address' => $street,
        ];

        if (! empty($parts['city'])) {
            $address['city'] = $parts['city'];
        }

        if (! empty($parts['postal'])) {
            $address['postal_code'] = $parts['postal'];
        }

        if (! empty($parts['country'])) {
            $address['country'] = $parts['country'];
        }

        return $address;
    }

    /**
     * Parse date + optional time window into ISO datetimes.
     *
     * @param string|null $date
     * @param string|null $timeWindow
     * @return array<string, string>
     */
    protected function parseDateAndTime(?string $date, ?string $timeWindow): array
    {
        $output = [];

        if ($date) {
            // Accept both d/m/yy and d/m/yyyy
            $formats = ['d/m/y', 'd/m/Y'];

            $base = null;
            foreach ($formats as $fmt) {
                try {
                    $base = Carbon::createFromFormat($fmt, $date);
                    break;
                } catch (\Exception $e) {
                    // continue
                }
            }

            if ($base === null) {
                // If parsing fails, return empty array
                return $output;
            }

            $from = clone $base;
            $to = clone $base;

            if ($timeWindow && preg_match('/([0-9]{1,2})h([0-9]{2})\s*-\s*([0-9]{1,2})h([0-9]{2})/i', $timeWindow, $m)) {
                $from->setTime((int) $m[1], (int) $m[2], 0);
                $to->setTime((int) $m[3], (int) $m[4], 0);
            } elseif ($timeWindow && preg_match('/\b([0-9]{1,2}):([0-9]{2})\b\s*-\s*\b([0-9]{1,2}):([0-9]{2})\b/', $timeWindow, $m)) {
                $from->setTime((int) $m[1], (int) $m[2], 0);
                $to->setTime((int) $m[3], (int) $m[4], 0);
            }

            $output['datetime_from'] = $from->toIsoString();
            if (! $to->equalTo($from)) {
                $output['datetime_to'] = $to->toIsoString();
            }
        }

        return $output;
    }

    /**
     * Extract cargos found in the PDF.
     *
     * @param array<int, string> $lines
     * @return array<int, array<string, mixed>>
     */
    protected function extractCargos(array $lines): array
    {
        $titleIndex = array_key_first(array_filter($lines, fn ($l) => Str::contains(Str::upper($l), 'PAPER ROLLS') || Str::contains(Str::upper($l), 'PALLETS') || Str::contains(Str::upper($l), 'WEIGHT'))) ?? null;
        $title = $titleIndex !== null ? trim($lines[$titleIndex]) : null;

        // weight line pattern (numbers with thousand separator)
        $weightIndex = array_key_first(array_filter($lines, fn ($l) => preg_match('/[0-9]{1,3}\.?[0-9]{3},?[0-9]*/', trim($l)) === 1)) ?? null;
        $weight = null;
        if ($weightIndex !== null) {
            $w = preg_replace('/[^0-9,\.]/', '', trim($lines[$weightIndex]));
            $weight = $w !== '' ? (float) str_replace(',', '.', $w) : null;
        }

        $numberIndex = array_key_first(array_filter($lines, fn ($l) => Str::contains(trim($l), 'OT :') || Str::contains(trim($l), 'REF'))) ?? null;
        $number = $numberIndex !== null ? trim($lines[$numberIndex + 2] ?? '') : null;

        $cargo = [
            'package_count' => 1,
            'package_type' => 'pallet',
        ];

        if (! empty($title)) {
            $cargo['title'] = $title;
        }

        if (! empty($number)) {
            $cargo['number'] = $number;
        }

        if ($weight !== null) {
            $cargo['weight'] = $weight;
        }

        return [$cargo];
    }

    /**
     * Parse a city/postal/country line into parts.
     *
     * Recognizes patterns like:
     *  - GB-PE2 6DP PETERBOROUGH
     *  - GB-DE14-BURTON ON TRENT
     *  - -37530 POCE-SUR-CISSE
     *
     * @param string $line
     * @return array{city?: string, postal?: string, country?: string}
     */
    private function parseCityPostalCountry(string $line): array
    {
        $country = null;
        $postal = null;
        $city = null;

        $line = trim($line);

        if ($line === '') {
            return compact('city', 'postal', 'country');
        }

        if (preg_match('/^([A-Z]{2})-([A-Z0-9 ]+)-(.+)$/', $line, $m)) {
            $country = $m[1];
            $postal = trim($m[2]);
            $city = trim($m[3]);
        } elseif (preg_match('/^([A-Z]{2})-([A-Z0-9 ]+)\s+(.+)$/', $line, $m)) {
            $country = $m[1];
            $postal = trim($m[2]);
            $city = trim($m[3]);
        } elseif (preg_match('/^-?([0-9]{4,6})\s+(.+)$/', $line, $m)) {
            $postal = trim($m[1]);
            $city = trim($m[2]);
            if (strlen($postal) === 5) {
                $country = 'FR';
            }
        } else {
            // fallback: if the line ends with a country name or ISO, try to detect last token
            $tokens = preg_split('/\s+/', $line);
            $last = strtoupper(end($tokens));
            if (strlen($last) === 2) {
                $country = $last;
                array_pop($tokens);
                $city = trim(implode(' ', $tokens));
            } else {
                // Try to find a postal code inside
                if (preg_match('/([A-Z0-9\-]{3,10})/', $line, $m2)) {
                    $postal = $m2[1];
                    $city = trim(str_replace($postal, '', $line));
                } else {
                    $city = $line;
                }
            }
        }

        return compact('city', 'postal', 'country');
    }

    /**
 * Split a lines array into smaller blocks by given keywords.
 *
 * @param array<int, string> $lines
 * @param array<int, string> $keywords
 * @return array<int, array<int, string>>
 */
    private function splitByKeyword(array $lines, array $keywords): array
    {
        $blocks = [];
        $current = [];

        foreach ($lines as $line) {
            $isNewBlock = false;
            foreach ($keywords as $kw) {
                if (Str::startsWith(Str::upper(trim($line)), Str::upper($kw))) {
                    $isNewBlock = true;
                    break;
                }
            }

            if ($isNewBlock && !empty($current)) {
                $blocks[] = $current;
                $current = [];
            }

            $current[] = $line;
        }

        if (!empty($current)) {
            $blocks[] = $current;
        }

        return $blocks;
    }

    /**
     * Parse booking-style location blocks like “Collection ...” or “Delivery ...”
     *
     * @param array<int, string> $block
     * @return array<string, mixed>|null
     */
    private function parseBookingLocationBlock(array $block): ?array
    {
        if (empty($block)) {
            return null;
        }

        // Try to extract known patterns
        $company = trim($block[1] ?? '');
        $refLine = array_values(array_filter($block, fn ($l) => Str::startsWith(Str::upper($l), 'REF'))) [0] ?? null;
        $timeLine = array_values(array_filter($block, fn ($l) => preg_match('/[0-9]{3,4}\s*-\s*[0-9]{1,2}(AM|PM)/i', $l) || preg_match('/[0-9]{1,2}h?[0-9]{2}/i', $l))) [0] ?? null;
        $dateLine = array_values(array_filter($block, fn ($l) => preg_match('/[0-3]?[0-9]\/[0-1]?[0-9]\/[0-9]{4}/', $l))) [0] ?? null;
        $addressLine = end($block);

        $companyAddress = [
            'company' => $company,
            'title' => $company,
            'street_address' => $addressLine,
            'country' => 'GB', // fallback
        ];

        $time = [];
        if ($dateLine) {
            try {
                $base = Carbon::createFromFormat('d/m/Y', $dateLine);
                $time['datetime_from'] = $base->toIsoString();
            } catch (\Exception $e) {
                $time['datetime_from'] = now()->toIsoString();
            }
        } else {
            // ✅ Fallback if no date detected
            $time['datetime_from'] = now()->toIsoString();
        }

        return [
            'company_address' => $companyAddress,
            'time' => !empty($time) ? (object)$time : new \stdClass(),
        ];
    }

}
