<?php

namespace Tests\AppBundle\Webfeeds;

use AppBundle\Webfeeds\Webfeeds;

class WebfeedsTest extends \PHPUnit\Framework\TestCase
{
    public function test()
    {
        $webfeeds = new Webfeeds();
        $webfeeds->setLogo('https://upload.wikimedia.org/wikipedia/commons/a/ab/Logo_TV_2015.png')
            ->setIcon('https://upload.wikimedia.org/wikipedia/commons/a/ab/Logo_TV_2015.png')
            ->setAccentColor('404040');

        $this->assertSame('https://upload.wikimedia.org/wikipedia/commons/a/ab/Logo_TV_2015.png', $webfeeds->getLogo());
        $this->assertSame('https://upload.wikimedia.org/wikipedia/commons/a/ab/Logo_TV_2015.png', $webfeeds->getIcon());
        $this->assertSame('404040', $webfeeds->getAccentColor());
    }
}
