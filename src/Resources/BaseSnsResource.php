<?php
namespace DreamFactory\Core\Aws\Resources;

use DreamFactory\Core\Resources\BaseRestResource;
use DreamFactory\Core\Aws\Services\Sns;

class BaseSnsResource extends BaseRestResource
{
    //*************************************************************************
    //	Constants
    //*************************************************************************

    //*************************************************************************
    //	Members
    //*************************************************************************

    /**
     * @var null|Sns
     */
    protected $service = null;

    /**
     * @var null|string
     */
    protected $parentResource = null;

    //*************************************************************************
    //	Methods
    //*************************************************************************

    /**
     * @param Sns   $service
     * @param array $settings
     */
    public function __construct($service = null, $settings = array())
    {
        parent::__construct($settings);

        $this->service = $service;
    }

    /**
     * @param Sns|null $service
     */
    public function setService($service)
    {
        $this->service = $service;
    }

    /**
     * @param null|string $parentResource
     *
     * @return $this
     */
    public function setParentResource($parentResource)
    {
        if (!empty($parentResource)) {
            $parentResource = $this->service->addArnPrefix($parentResource);
        }
        $this->parentResource = $parentResource;

        return $this;
    }
}