<?php

declare(strict_types=1);

namespace App\UseCases\BuildingUseCases\UpdateBuildingUseCase;

use App\Domain\Interfaces\BuildingInterfaces\BuildingFactory;
use App\Domain\Interfaces\BuildingInterfaces\BuildingRepository;
use App\Domain\Interfaces\SiteInterfaces\SiteRepository;
use App\Domain\Interfaces\ViewModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class UpdateBuildingInteractor implements UpdateBuildingInputPort
{
    /**
     * @param  UpdateBuildingOutputPort  $output
     * @param  BuildingRepository  $buildingRepository
     * @param  SiteRepository  $siteRepository
     * @param  BuildingFactory  $buildingFactory
     */
    public function __construct(
        private readonly UpdateBuildingOutputPort $output,
        private readonly BuildingRepository $buildingRepository,
        private readonly SiteRepository $siteRepository,
        private readonly BuildingFactory $buildingFactory
    ) {
    }

    /**
     * @param  UpdateBuildingRequestModel  $request
     * @return ViewModel
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function updateBuilding(UpdateBuildingRequestModel $request): ViewModel
    {
        $buildingUpdating = $this->buildingFactory->makeFromPatchRequest($request);

        // Try to get building
        try {
            $building = $this->buildingRepository->getById($buildingUpdating->getId());
        } catch (\Exception $e) {
            return $this->output->noSuchBuilding(
                resolve_proxy(UpdateBuildingResponseModel::class, ['building' => $buildingUpdating])
            );
        }

        $site = $this->siteRepository->getById($building->getSiteId());

        // User department check
        if (! Gate::allows('departmentCheck', $building->getDepartmentId())) {
            return $this->output->permissionException(
                resolve_proxy(UpdateBuildingResponseModel::class, ['building' => $buildingUpdating])
            );
        }

        $buildingUpdating->setUpdatedBy($request->getUserName());

        DB::beginTransaction();

        DB::table('building')
            ->where('site_id', $site->getId())
            ->lockForUpdate()
            ->get();

        $buildingNamesList = $this->buildingRepository->getNamesListBySiteId($site->getId());

        // Name check (can not be repeated inside one site)
        if (! $buildingUpdating->isNameValid($buildingUpdating->getName(), $buildingNamesList) &&
            $building->getName() !== $buildingUpdating->getName()
        ) {
            return $this->output->buildingNameException(
                resolve_proxy(UpdateBuildingResponseModel::class, ['building' => $buildingUpdating])
            );
        }

        // Try to update
        try {
            $buildingUpdating = $this->buildingRepository->update($buildingUpdating);
        } catch (\Exception $e) {
            return $this->output->unableToUpdateBuilding(
                resolve_proxy(UpdateBuildingResponseModel::class, ['building' => $buildingUpdating]),
                $e
            );
        }

        DB::commit();

        Log::channel('action_log')->info("Update Building --> pk {$building->getId()}", [
            'old_data' => $building->toArray(),
            'new_data' => $buildingUpdating->toArray(),
            'by_user' => $request->getUserName(),
        ]);

        return $this->output->buildingUpdated(
            resolve_proxy(UpdateBuildingResponseModel::class, ['building' => $buildingUpdating])
        );
    }
}
