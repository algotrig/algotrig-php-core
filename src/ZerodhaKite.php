<?php

declare(strict_types=1);

namespace AlgoTrig\PhpCore;

use KiteConnect\KiteConnect;
use stdClass;
use Exception;
use RuntimeException;

/**
 * **ZerodhaKite** is the part of **[AlgoTrig](https://algotrig.in)** project. © 2025 AlgoTrig.
 *
 * **ZerodhaKite** abstracts the Kite Connect API client for PHP - **[kite.trade](https://kite.trade)**.
 *
 * **ZerodhaKite** exposes methods to execute trades as per **Algorithmic Triggers**
 * defined as per **[AlgoTrig](https://algotrig.in)** standards.
 *
 * **ZerodhaKite** is opensource library licensed under the MIT License.
 *
 * **ZerodhaKite** can be downloaded from [Github](https://github.com/algotrig/algotrig-php-core).
 * Also available via [Packagist](https://packagist.org/packages/algotrig/algotrig-php-core) using [Composer](https://getcomposer.org/):
 * *composer require algotrig/algotrig-php-core*
 *
 */
class ZerodhaKite {
    private KiteConnect $kite;
    private array $config;
    private string $stockExchangeKey;
    private array $kiteHoldings;
    private mixed $kitePositions;
    private mixed $kiteLtps;
    private array $kiteOrders;
    private array $tradingSymbols;
    private array $quoteSymbols;
    private array $holdingKeys;
    private array $dayPositionsObjects;
    private float $totalBuyAmount;
    private float $targetValue;
    private float $maxCurrentValue;
    private array $tradingData;
    private array $executedOrders;
    private array $executedOrdersData;
    private array $failedOrders;

    /**
     * Parameterized constructor with configurations and access token as parameters
     *
     * @param array $config The config array that contains Zerodha configurations
     * @param string $accessToken The access token received from Zerodha Kite after successful login
     * @return ZerodhaKite object instance
     */
    public function __construct(array $config, string $accessToken) {
        $this->validate($config);
        $this->config = $config;
        $this->initializeKite($accessToken);
        $this->stockExchangeKey = $config['stock_exchange_key'];
        $this->dayPositionsObjects = [];
        $this->holdingKeys = [];
        $this->kiteOrders = [];
        $this->tradingSymbols = [];
        $this->quoteSymbols = [];
        $this->maxCurrentValue = 0.0;
        $this->targetValue = 0.0;
        $this->executedOrders = [];
        $this->executedOrdersData = [];
        $this->failedOrders = [];
    }

    /**
     * Initialize KiteConnect object using the given access token
     * 
     * @param string $accessToken The access token received from Zerodha Kite after successful login
     * @return void
     */
    private function initializeKite($accessToken): void {
        // Initialize KiteConnect
        try {
            $this->kite = new KiteConnect(
                $this->config['api_key'],
                $accessToken
            );
        } catch (Exception $e) {
            error_log("KiteConnect initialization failed: " . $e->getMessage());
            header('Location: /logout.php');
            exit;
        }
    }

    /**
     * Validate required configuration values
     * 
     * @param array $config The config array containing the configuration values
     * @return void
     */
    private function validate(array $config): void {
        $requiredValues = [
            'api_key' => 'Zerodha API Key',
            'secret' => 'Zerodha Secret Key'
        ];

        foreach ($requiredValues as $path => $name) {
            if (!isset($config[$path]) || empty($config[$path])) {
                throw new RuntimeException("Required configuration value '{$name}' is missing or invalid for Zerodha Kite");
            }
        }
    }

    /**
     * Fetch margin information from kite for given segment
     *
     * @param string|null $segment (Optional) The trading segment for which to fetch margins.
     * If null, margins for all segments are retrieve
     * @return mixed The margin information fetched from kite
     */
    public function fetchMargins(?string $segment = null): mixed {
        $margins = $this->kite->getMargins($segment);
        if (is_null($segment)) {
            return $margins;
        }
        $obj = new stdClass();
        $obj->$segment = $margins;
        return $obj;
    }

    /**
     * Process the Trading Data as per the Holdings, Positions and LTP
     * 
     * @param float $targetValue The target value for Holdings to execute the buy trades
     * @return void
     */
    public function process(float $targetValue = 0.0): void {
        // Fetch kiteHoldings
        $this->fetchHoldings();

        // Fetch kitePositions
        $this->fetchPositions();

        // Process day kitePositions
        $this->processDayPositions();

        // Process symbols
        $this->processSymbols();

        // Add Nifty 50 to quotes
        $this->addQuoteSymbol($this->getQuoteSymbol("NIFTY 50"));

        // Fetch LTP data
        $this->fetchLTPData();

        // Update holding quantities
        $this->updateHoldingQuantities();

        // Calculate max current value
        $this->calculateMaxCurrentValue();

        // Calculate target value if not set
        $this->targetValue = $targetValue > 0.0 ? $targetValue : $this->getMaxCurrentValue();

        // Process trading data
        $this->processTradingData();
    }

    /**
     * Fetch kiteHoldings from Kite
     *
     * @return void
     */
    private function fetchHoldings(): void {
        try {
            $this->kiteHoldings = $this->kite->getHoldings();
        } catch (Exception $e) {
            error_log("Failed to fetch kiteHoldings: " . $e->getMessage());
            trigger_error("Triggered error: Failed to fetch kiteHoldings: " . $e->getMessage(), E_USER_ERROR);
        }
    }

    /**
     * Fetch kitePositions from Kite
     *
     * @return void
     */
    private function fetchPositions(): void {
        try {
            $this->kitePositions = $this->kite->getPositions();
        } catch (Exception $e) {
            error_log("Failed to fetch kitePositions: " . $e->getMessage());
            trigger_error("Triggered error: Failed to fetch kitePositions: " . $e->getMessage(), E_USER_ERROR);
        }
    }

    /**
     * Process day kitePositions
     *
     * @return void
     */
    private function processDayPositions(): void {
        // Process kitePositions
        foreach ($this->kitePositions->day as $index => $position) {
            $this->dayPositionsObjects[$position->tradingsymbol] = $position;
        }
    }

    /**
     * Get trading symbols, quote symbols and holding keys from kiteHoldings
     *
     * @return void
     */
    private function processSymbols(): void {
        foreach ($this->kiteHoldings as $index => $holding) {
            $this->tradingSymbols[] = $holding->tradingsymbol;
            $this->quoteSymbols[] = $this->getQuoteSymbol($holding->tradingsymbol);
            $this->holdingKeys[$holding->tradingsymbol] = $index;
        }
    }

    /**
     * Add the given quote symbol to the existing list
     * 
     * @param string $quoteSymbol The quote symbol to be added
     * @return void
     */
    public function addQuoteSymbol(string $quoteSymbol): void {
        $this->quoteSymbols[] = $quoteSymbol;
    }

    /**
     * Update holding quantities with day kitePositions
     *
     * @return void
     */
    private function updateHoldingQuantities(): void {
        // Update holding quantities with day kitePositions
        foreach ($this->tradingSymbols as $ts) {
            $holdingQty = $this->kiteHoldings[$this->holdingKeys[$ts]]->opening_quantity;
            if (isset($this->dayPositionsObjects[$ts])) {
                $holdingQty += intval($this->dayPositionsObjects[$ts]->quantity);
            }
            $this->kiteHoldings[$this->holdingKeys[$ts]]->holding_quantity = $holdingQty;
        }
    }

    /**
     * Fetch LTP data from Kite
     *
     * @return void
     */
    private function fetchLTPData(): void {
        // Get LTP data
        try {
            $this->kiteLtps = $this->kite->getLTP($this->quoteSymbols);
        } catch (Exception $e) {
            error_log("Failed to fetch LTP data: " . $e->getMessage());
            trigger_error("Triggered error: Failed to fetch LTP data: " . $e->getMessage(), E_USER_ERROR);
        }
    }

    /**
     * Get LTP for given quote symbol from fetched LTPs
     *
     * @param string $quoteSymbol The quote symbol to get the LTP
     * @return float The LTP
     */
    public function getLTPforQuoteSymbol(string $quoteSymbol): float {
        return floatval($this->kiteLtps->$quoteSymbol->last_price ?? 0);
    }

    /**
     * Get LTP for given trading symbol from fetched LTPs
     *
     * @param string $tradingSymbol The trading symbol to get the LTP
     * @return float The LTP
     */
    public function getLTPforTradingSymbol(string $tradingSymbol): float {
        return $this->getLTPforQuoteSymbol($this->getQuoteSymbol($tradingSymbol));
    }

    /**
     * Calculate Max Current Value
     *
     * @return void
     */
    private function calculateMaxCurrentValue(): void {
        foreach ($this->tradingSymbols as $tradingSymbol) {
            if ($this->shouldSkipSymbol($tradingSymbol)) {
                continue;
            }

            $quoteSymbol = $this->getQuoteSymbol($tradingSymbol);
            $ltp = (float)($this->kiteLtps->$quoteSymbol->last_price ?? 0);
            $holdingQty = $this->kiteHoldings[$this->holdingKeys[$tradingSymbol]]->holding_quantity ?? 0;
            $currentValue = (float)((int)$holdingQty * $ltp);

            $this->maxCurrentValue = max($this->maxCurrentValue, $currentValue);
        }
    }

    /**
     * Get quote symbol for a trading symbol
     *
     * @param string $tradingSymbol The trading symbol
     * @return string The quote symbol
     */
    public function getQuoteSymbol(string $tradingSymbol): string {
        return $this->stockExchangeKey . ":" . $tradingSymbol;
    }

    /**
     * Process trading data for each symbol
     *
     * @return void
     */
    private function processTradingData(): void {
        $this->totalBuyAmount = 0.0;

        foreach ($this->tradingSymbols as $symbol) {
            if ($this->shouldSkipSymbol($symbol)) {
                continue;
            }

            $quoteSymbol = $this->getQuoteSymbol($symbol);
            $ltpObj = $this->kiteLtps->$quoteSymbol;

            $obj = new stdClass();
            $obj->trading_symbol = $symbol;
            $obj->quote_symbol = $quoteSymbol;
            $obj->instrument_token = $ltpObj->instrument_token;

            $openingQty = $this->kiteHoldings[$this->holdingKeys[$symbol]]->opening_quantity;
            $holdingQty = $this->kiteHoldings[$this->holdingKeys[$symbol]]->holding_quantity;
            $obj->opening_quantity = $openingQty;
            $obj->holding_quantity = $holdingQty;

            $ltp = floatval($ltpObj->last_price);
            $obj->ltp = number_format($ltp, 2, '.', '');

            $currentValue = intval($holdingQty) * $ltp;
            $obj->current_value = number_format($currentValue, 2, '.', '');

            $difference = $this->targetValue - $currentValue;
            $obj->difference = number_format($difference, 2, '.', '');

            $buyQty = $difference > 0.0 ? floor($difference / $ltp) : 0.0;

            $obj->buy_qty = $buyQty;
            $buyAmount = $buyQty * $ltp;
            $obj->buy_amt = number_format($buyAmount, 2, '.', '');
            $this->totalBuyAmount += $buyAmount;

            $obj->proposed_value = number_format($currentValue + $buyAmount, 2, '.', '');

            if ($currentValue === $this->maxCurrentValue) {
                //$obj->trading_symbol = "*" . $symbol;
            }

            $obj->sell_qty = 0; // TODO: this is a quick fix, needs to be changed

            $this->tradingData[$symbol] = $obj;
            if ($obj->buy_qty > 0) {
                $this->kiteOrders[] = $this->getOrder($obj, "BUY");
            }
        }
    }

    /**
     * Execute orders
     *
     * @return void
     */
    public function executeOrders(): void {
        foreach ($this->kiteOrders as $order) {
            try {
                $this->executedOrdersData[] = $this->executeOrder($order);
                $this->executedOrders[] = $order;
            } catch (Exception $e) {
                error_log("Error executing order: " . $e->getMessage());
                $order['error'] = $e;
                $this->failedOrders[] = $order;
            }
        }
    }

    /**
     * Execute order
     *
     * @return mixed The executed order data
     */
    public function executeOrder(array $order): mixed {
        return $this->kite->placeOrder("regular", $order);
    }

    /**
     * Get the trading data
     * processed by $this->processTradingData()
     * in $this->process()
     *
     * @return array The trading data
     */
    public function getTradingData(): array {
        return $this->tradingData;
    }

    /**
     * Get the executed orders data
     *
     * @return array The executed orders data
     */
    public function getExecutedOrdersData(): array {
        return $this->executedOrdersData;
    }

    /**
     * Check if a symbol should be skipped in processing
     *
     * @param string $symbol Trading symbol to check
     * @return bool True if symbol should be skipped
     */
    private function shouldSkipSymbol(string $symbol): bool {
        return in_array($symbol, ["SETFNIF50", "NIFTYBEES", "LIQUIDBEES"]);
    }

    /**
     * Generate order data for a trading symbol
     *
     * @param object $obj The trading object
     * @param string $tradeType The trade type "BUY" or "SELL"
     * @return array Order data
     */
    public function getOrder(object $obj, string $tradeType): array {
        $tradingSymbol = $obj->trading_symbol;
        $isSpecialSymbol = in_array($tradingSymbol, ["FMCGIETF", "HDFCSENSEX"]);

        $quantity = $this->getQuantityForTradeType($obj, $tradeType);

        if ($isSpecialSymbol) {
            return $this->getLimitOrder($tradingSymbol, $quantity, $tradeType);
        }

        return $this->buildMarketOrder($tradingSymbol, $quantity, $tradeType);
    }

    /**
     * Get quantity for given trade type from the given object
     * If trade type is "BUY" then $obj->buy_qty
     * If trade type is "SELL" then $obj->sell_qty
     * Otherwise throw RuntimeException
     *
     * @return int The quantity
     */
    private function getQuantityForTradeType(object $obj, string $tradeType): int {
        if ($tradeType == "BUY") {
            return intval($obj->buy_qty);
        } elseif ($tradeType == "SELL") {
            return intval($obj->sell_qty);
        }
        throw new RuntimeException("Invalid Trade Type");
    }

    /**
     * Build an array to place MARKET order with given parameters
     *
     * @param string $tradingSymbol The trading symbol
     * @param int $quantity The quantity
     * @param string $tradeType The trade type "BUY" or "SELL"
     * @return array The array with MARKET order data
     */
    private function buildMarketOrder(string $tradingSymbol, int $quantity, string $tradeType): array {
        return [
            "tradingsymbol" => $tradingSymbol,
            "exchange" => $this->stockExchangeKey,
            "quantity" => $quantity,
            "transaction_type" => $tradeType,
            "order_type" => "MARKET",
            "product" => "CNC"
        ];
    }

    /**
     * Builds an array to place LIMIT order with given parameters
     *
     * @param string $tradingSymbol The trading symbol
     * @param int $quantity The quantity
     * @param string $tradeType The trade type "BUY" or "SELL"
     * @param float $price The limit price
     * @return array The array with LIMIT order data
     */
    private function buildLimitOrder(string $tradingSymbol, int $quantity, string $tradeType, float $price): array {
        return [
            "tradingsymbol" => $tradingSymbol,
            "exchange" => $this->stockExchangeKey,
            "quantity" => $quantity,
            "transaction_type" => $tradeType,
            "order_type" => "LIMIT",
            "price" => $price,
            "product" => "CNC"
        ];
    }

    /**
     * Get the quote type from trade type
     * If trade type is "BUY" return "sell"
     * If trade type is "SELL" return "buy"
     * Otherwise throw RuntimeException
     *
     * @param string $tradeType The trade type to get quote type
     * @return string The quote type
     * @throws RuntimeException if $tradeType is anything other than "BUY" or "SELL"
     */
    private function getQuoteTypeFromTradeType(string $tradeType): string {
        if ($tradeType == "BUY") {
            return "sell";
        } elseif ($tradeType == "SELL") {
            return "buy";
        }
        throw new RuntimeException("Invalid Trade Type");
    }

    /**
     * Get an array to place LIMIT order with given parameters
     *
     * @param string $tradingSymbol The trading symbol
     * @param int $quantity The quantity
     * @param string $tradeType The trade type "BUY" or "SELL"
     * @return array The array with LIMIT order data
     */
    private function getLimitOrder(string $tradingSymbol, int $quantity, string $tradeType): array {
        $quote = $this->fetchQuote($tradingSymbol, $this->getQuoteTypeFromTradeType($tradeType), 4);
        return $this->buildLimitOrder($tradingSymbol, $quantity, $tradeType, $quote);
    }

    /**
     * Fetch the quotes for given trading symbol
     *
     * @param string $tradingSymbol The trading symbol to fetch the quotes
     * @param string $quoteType The "buy" or "sell" quote type
     * @param int $depth The quote depth
     * @return float The fetched quote
     */
    private function fetchQuote(string $tradingSymbol, string $quoteType, int $depth = 0): float {
        $quoteSymbol = $this->getQuoteSymbol($tradingSymbol);
        $quotes = $this->kite->getQuote([$quoteSymbol]);
        return floatval($quotes[$quoteSymbol]->depth->$quoteType[$depth]->price);
    }

    /**
     * Get max current value
     *
     * @return float The max current value
     */
    public function getMaxCurrentValue(): float {
        return $this->maxCurrentValue;
    }

    /**
     * Get target value
     *
     * @return float The target value
     */
    public function getTargetValue(): float {
        return $this->targetValue;
    }

    /**
     * Get total buy amount
     *
     * @return float The total buy amount
     */
    public function getTotalBuyAmount(): float {
        return $this->totalBuyAmount;
    }

    /**
     * Get failed orders
     *
     * @return array The failed orders
     */
    public function getFailedOrders(): array {
        return $this->failedOrders;
    }
}
