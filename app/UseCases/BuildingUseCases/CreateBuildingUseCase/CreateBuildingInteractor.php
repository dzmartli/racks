<?php

declare(strict_types=1);

namespace App\UseCases\BuildingUseCases\CreateBuildingUseCase;

use App\Domain\Interfaces\BuildingInterfaces\BuildingFactory;
use App\Domain\Interfaces\BuildingInterfaces\BuildingRepository;
use App\Domain\Interfaces\SiteInterfaces\SiteRepository;
use App\Domain\Interfaces\ViewModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class CreateBuildingInteractor implements CreateBuildingInputPort
{
    /**
     * @param  CreateBuildingOutputPort  $output
     * @param  BuildingRepository  $buildingRepository
     * @param  SiteRepository  $siteRepository
     * @param  BuildingFactory  $buildingFactory
     */
    public function __construct(
        private readonly CreateBuildingOutputPort $output,
        private readonly BuildingRepository $buildingRepository,
        private readonly SiteRepository $siteRepository,
        private readonly BuildingFactory $buildingFactory
    ) {
    }

    /**
     * @param  CreateBuildingRequestModel  $request
     * @return ViewModel
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function createBuilding(CreateBuildingRequestModel $request): ViewModel
    {
        $building = $this->buildingFactory->makeFromCreateRequest($request);

        // Try to get site
        try {
            $site = $this->siteRepository->getById($request->getSiteId());
        } catch (\Exception $e) {
            return $this->output->noSuchSite(
                resolve_proxy(CreateBuildingResponseModel::class, ['building' => $building])
            );
        }

        // User department check
        if (! Gate::allows('departmentCheck', $site->getDepartmentId())) {
            return $this->output->permissionException(
                resolve_proxy(CreateBuildingResponseModel::class, ['building' => $building])
            );
        }

        $building->setUpdatedBy($request->getUserName());

        $building->setDepartmentId($site->getDepartmentId());

        DB::beginTransaction();

        try {
            DB::table('sites')
                ->where('id', $site->getId())
                ->lockForUpdate()
                ->first();

            $buildingNamesList = $this->buildingRepository->getNamesListBySiteId($site->getId());

            // Name check (can not be repeated inside one site)
            if (! $building->isNameValid($building->getName(), $buildingNamesList)) {
                DB::rollBack();

                return $this->output->buildingNameException(
                    resolve_proxy(CreateBuildingResponseModel::class, ['building' => $building])
                );
            }

            $building = $this->buildingRepository->create($building);

            $building = $building->fresh([]);

            DB::commit();
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return $this->output->unableToCreateBuilding(
                resolve_proxy(CreateBuildingResponseModel::class, ['building' => $building]),
                $e
            );
        }

        Log::channel('action_log')->info("Create Building --> fk {$site->getId()}", [
            'new_data' => $building->toArray(),
            'by_user' => $request->getUserName(),
        ]);

        return $this->output->buildingCreated(
            resolve_proxy(CreateBuildingResponseModel::class, ['building' => $building])
        );
    }
}
