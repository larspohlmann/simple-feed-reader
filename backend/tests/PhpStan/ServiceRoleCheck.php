<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

enum ServiceRoleCheck: string
{
    case InterfaceName = 'interfaceName';
    case InterfaceFolder = 'interfaceFolder';
    case FactoryName = 'factoryName';
    case FactoryFolder = 'factoryFolder';
    case ModelName = 'modelName';
    case ModelFolder = 'modelFolder';
    case ModelShape = 'modelShape';
    case DtoShape = 'dtoShape';
    case ModelHome = 'modelHome';
    case PassHome = 'passHome';
    case SupportHome = 'supportHome';
    case SupportShape = 'supportShape';
    case RootService = 'rootService';
    case StatefulService = 'statefulService';
    case ListenerName = 'listenerName';
    case HandlerName = 'handlerName';

    public function identifier(): string
    {
        return 'simpleFeedReader.serviceRole.' . $this->value;
    }
}
