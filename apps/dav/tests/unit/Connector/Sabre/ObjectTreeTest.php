<?php
/**
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 *
 * @author Joas Schilling <coding@schilljs.com>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Robin Appelman <robin@icewind.nl>
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Vincent Petry <pvince81@owncloud.com>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\DAV\Tests\unit\Connector\Sabre;


use OC\Files\FileInfo;
use OC\Files\Filesystem;
use OC\Files\Mount\Manager;
use OC\Files\Storage\Temporary;
use OC\Files\View;
use OCA\DAV\Connector\Sabre\Directory;
use OCA\DAV\Connector\Sabre\ObjectTree;

/**
 * Class ObjectTreeTest
 *
 * @group DB
 *
 * @package OCA\DAV\Tests\Unit\Connector\Sabre
 */
class ObjectTreeTest extends \Test\TestCase {

	public function copyDataProvider() {
		return [
			// copy into same dir
			['a', 'b', ''],
			// copy into same dir
			['a/a', 'a/b', 'a'],
			// copy into another dir
			['a', 'sub/a', 'sub'],
		];
	}

	/**
	 * @dataProvider copyDataProvider
	 */
	public function testCopy($sourcePath, $targetPath, $targetParent) {
		$view = $this->createMock(View::class);
		$view->expects($this->once())
			->method('verifyPath')
			->with($targetParent)
			->will($this->returnValue(true));
		$view->expects($this->once())
			->method('file_exists')
			->with($targetPath)
			->willReturn(false);
		$view->expects($this->once())
			->method('copy')
			->with($sourcePath, $targetPath)
			->will($this->returnValue(true));

		$info = $this->createMock(FileInfo::class);
		$info->expects($this->once())
			->method('isCreatable')
			->willReturn(true);

		$view->expects($this->once())
			->method('getFileInfo')
			->with($targetParent === '' ? '.' : $targetParent)
			->willReturn($info);

		$rootDir = new Directory($view, $info);
		$objectTree = $this->getMockBuilder(ObjectTree::class)
			->setMethods(['nodeExists', 'getNodeForPath'])
			->setConstructorArgs([$rootDir, $view])
			->getMock();

		$objectTree->expects($this->once())
			->method('getNodeForPath')
			->with($this->identicalTo($sourcePath))
			->will($this->returnValue(false));

		/** @var $objectTree \OCA\DAV\Connector\Sabre\ObjectTree */
		$mountManager = Filesystem::getMountManager();
		$objectTree->init($rootDir, $view, $mountManager);
		$objectTree->copy($sourcePath, $targetPath);
	}

	/**
	 * @dataProvider copyDataProvider
	 * @expectedException \Sabre\DAV\Exception\Forbidden
	 */
	public function testCopyFailNotCreatable($sourcePath, $targetPath, $targetParent) {
		$view = $this->createMock(View::class);
		$view->expects($this->never())
			->method('verifyPath');
		$view->expects($this->once())
			->method('file_exists')
			->with($targetPath)
			->willReturn(false);
		$view->expects($this->never())
			->method('copy');

		$info = $this->createMock(FileInfo::class);
		$info->expects($this->once())
			->method('isCreatable')
			->willReturn(false);

		$view->expects($this->once())
			->method('getFileInfo')
			->with($targetParent === '' ? '.' : $targetParent)
			->willReturn($info);

		$rootDir = new Directory($view, $info);
		$objectTree = $this->getMockBuilder(ObjectTree::class)
			->setMethods(['nodeExists', 'getNodeForPath'])
			->setConstructorArgs([$rootDir, $view])
			->getMock();

		$objectTree->expects($this->never())
			->method('getNodeForPath');

		/** @var $objectTree \OCA\DAV\Connector\Sabre\ObjectTree */
		$mountManager = Filesystem::getMountManager();
		$objectTree->init($rootDir, $view, $mountManager);
		$objectTree->copy($sourcePath, $targetPath);
	}

	/**
	 * @dataProvider nodeForPathProvider
	 */
	public function testGetNodeForPath(
		$inputFileName,
		$fileInfoQueryPath,
		$outputFileName,
		$type,
		$enableChunkingHeader
	) {

		if ($enableChunkingHeader) {
			$_SERVER['HTTP_OC_CHUNKED'] = true;
		}

		$rootNode = $this->getMockBuilder(Directory::class)
			->disableOriginalConstructor()
			->getMock();
		$mountManager = $this->getMockBuilder(Manager::class)
			->disableOriginalConstructor()
			->getMock();
		$view = $this->getMockBuilder(View::class)
			->disableOriginalConstructor()
			->getMock();
		$fileInfo = $this->getMockBuilder(FileInfo::class)
			->disableOriginalConstructor()
			->getMock();
		$fileInfo->expects($this->once())
			->method('getType')
			->will($this->returnValue($type));
		$fileInfo->expects($this->once())
			->method('getName')
			->will($this->returnValue($outputFileName));

		$view->expects($this->once())
			->method('getFileInfo')
			->with($fileInfoQueryPath)
			->will($this->returnValue($fileInfo));

		$tree = new \OCA\DAV\Connector\Sabre\ObjectTree();
		$tree->init($rootNode, $view, $mountManager);

		$node = $tree->getNodeForPath($inputFileName);

		$this->assertNotNull($node);
		$this->assertEquals($outputFileName, $node->getName());

		if ($type === 'file') {
			$this->assertTrue($node instanceof \OCA\DAV\Connector\Sabre\File);
		} else {
			$this->assertTrue($node instanceof \OCA\DAV\Connector\Sabre\Directory);
		}

		unset($_SERVER['HTTP_OC_CHUNKED']);
	}

	function nodeForPathProvider() {
		return array(
			// regular file
			array(
				'regularfile.txt',
				'regularfile.txt',
				'regularfile.txt',
				'file',
				false
			),
			// regular directory
			array(
				'regulardir',
				'regulardir',
				'regulardir',
				'dir',
				false
			),
			// regular file with chunking
			array(
				'regularfile.txt',
				'regularfile.txt',
				'regularfile.txt',
				'file',
				true
			),
			// regular directory with chunking
			array(
				'regulardir',
				'regulardir',
				'regulardir',
				'dir',
				true
			),
			// file with chunky file name
			array(
				'regularfile.txt-chunking-123566789-10-1',
				'regularfile.txt',
				'regularfile.txt',
				'file',
				true
			),
			// regular file in subdir
			array(
				'subdir/regularfile.txt',
				'subdir/regularfile.txt',
				'regularfile.txt',
				'file',
				false
			),
			// regular directory in subdir
			array(
				'subdir/regulardir',
				'subdir/regulardir',
				'regulardir',
				'dir',
				false
			),
			// file with chunky file name in subdir
			array(
				'subdir/regularfile.txt-chunking-123566789-10-1',
				'subdir/regularfile.txt',
				'regularfile.txt',
				'file',
				true
			),
		);
	}

	/**
	 * @expectedException \OCA\DAV\Connector\Sabre\Exception\InvalidPath
	 */
	public function testGetNodeForPathInvalidPath() {
		$path = '/foo\bar';


		$storage = new Temporary([]);

		$view = $this->getMockBuilder(View::class)
			->setMethods(['resolvePath'])
			->getMock();
		$view->expects($this->once())
			->method('resolvePath')
			->will($this->returnCallback(function($path) use ($storage){
			return [$storage, ltrim($path, '/')];
		}));

		$rootNode = $this->getMockBuilder(Directory::class)
			->disableOriginalConstructor()
			->getMock();
		$mountManager = $this->getMockBuilder(Manager::class)
			->getMock();

		$tree = new \OCA\DAV\Connector\Sabre\ObjectTree();
		$tree->init($rootNode, $view, $mountManager);

		$tree->getNodeForPath($path);
	}

	public function testGetNodeForPathRoot() {
		$path = '/';


		$storage = new Temporary([]);

		$view = $this->getMockBuilder(View::class)
			->setMethods(['resolvePath'])
			->getMock();
		$view->expects($this->any())
			->method('resolvePath')
			->will($this->returnCallback(function ($path) use ($storage) {
				return [$storage, ltrim($path, '/')];
			}));

		$rootNode = $this->getMockBuilder(Directory::class)
			->disableOriginalConstructor()
			->getMock();
		$mountManager = $this->getMockBuilder(Manager::class)
			->getMock();

		$tree = new \OCA\DAV\Connector\Sabre\ObjectTree();
		$tree->init($rootNode, $view, $mountManager);

		$this->assertInstanceOf('\Sabre\DAV\INode', $tree->getNodeForPath($path));
	}
}
