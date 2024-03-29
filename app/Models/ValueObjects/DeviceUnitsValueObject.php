<?php

namespace App\Models\ValueObjects;

use App\Domain\Interfaces\DeviceInterfaces\DeviceUnitsInterface;
use App\Http\Validators\Rules\DeviceUnitsRule;
use Illuminate\Support\Facades\Validator;

/**
 * Device units value object
 * Units occupied by the device
 */
class DeviceUnitsValueObject implements DeviceUnitsInterface
{
    /**
     * @var array<int>
     */
    private array $units;

    /**
     * @param  array<int>  $units
     *
     * @throws \Exception $units is not valid
     */
    public function __construct(array $units)
    {
        $this->validateUnits($units);
        $this->units = $units;
    }

    /**
     * @return array<int>
     */
    public function toArray(): array
    {
        return $this->units;
    }

    /**
     * @param  array<int>  $units
     * @return void
     *
     * @throws \DomainException $units is not valid
     */
    public function validateUnits(array $units): void
    {
        sort($units);
        $validator = Validator::make(['units' => $units], [
            'units' => [new DeviceUnitsRule(), 'array'],
        ]);
        if ($validator->fails()) {
            throw new \DomainException('$units is not valid');
        }
    }
}
