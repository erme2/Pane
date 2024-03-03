<?php

namespace Tests\Unit\Models;

use App\Models\AbstractModel;
use Tests\TestCase;
use Tests\TestsHelper;

class AbstractModelTest extends TestCase
{
    use TestsHelper;

    public function test_new_instance(): void
    {
        $keyName = 'not_id';
        $model = new AbstractModel();
        $model->setKeyName($keyName);
        $newModel = $model->newInstance();
        $this->assertEquals($model->getKeyName(), $newModel->getKeyName());
    }

    public function test_get_set_map_name(): void
    {
        $mapName = 'map_name';
        $model = new AbstractModel();
        $this->assertInstanceOf(AbstractModel::class, $model->setMapName($mapName));
        $this->assertEquals($mapName, $model->getMapName());
    }
}
