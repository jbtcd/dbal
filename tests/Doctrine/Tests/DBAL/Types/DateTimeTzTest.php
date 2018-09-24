<?php

namespace Doctrine\Tests\DBAL\Types;

use DateTime;
use Doctrine\DBAL\Types\Type;

class DateTimeTzTest extends BaseDateTypeTestCase
{
    /**
     * {@inheritDoc}
     */
    protected function setUp()
    {
        $this->type = Type::getType('datetimetz');

        parent::setUp();
    }

    public function testDateTimeConvertsToDatabaseValue()
    {
        $date = new DateTime('1985-09-01 10:10:10');

        $expected = $date->format($this->platform->getDateTimeTzFormatString());
        $actual   = $this->type->convertToDatabaseValue($date, $this->platform);

        self::assertEquals($expected, $actual);
    }

    public function testDateTimeConvertsToPHPValue()
    {
        // Birthday of jwage and also birthday of Doctrine. Send him a present ;)
        $date = $this->type->convertToPHPValue('1985-09-01 00:00:00', $this->platform);
        self::assertInstanceOf('DateTime', $date);
        self::assertEquals('1985-09-01 00:00:00', $date->format('Y-m-d H:i:s'));
    }

    public function testInvalidDateFormatConversion()
    {
        $this->expectException('Doctrine\DBAL\Types\ConversionException');
        $this->type->convertToPHPValue('abcdefg', $this->platform);
    }
}
