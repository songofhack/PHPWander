<?php declare(strict_types=1);

namespace PHPWander\Utils;

/**
 * @author Pavel Jurásek
 */
interface IConfiguration
{

	public function getAll(): iterable;

	public function getSection(string $section): iterable;

}
