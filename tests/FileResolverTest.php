<?php

declare(strict_types=1);

namespace Stejac\CodeThresholds\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use Stejac\CodeThresholds\Config;
use Stejac\CodeThresholds\Exception\ConfigException;
use Stejac\CodeThresholds\Exception\GitException;
use Stejac\CodeThresholds\FileResolver;
use Stejac\CodeThresholds\Git;
use Stejac\CodeThresholds\Mode;
use Stejac\CodeThresholds\Options;
use Stejac\CodeThresholds\Tests\Support\GitRepository;
use Stejac\CodeThresholds\Tests\Support\TemporaryDirectoryTestCase;

#[CoversClass(FileResolver::class)]
#[CoversClass(Git::class)]
final class FileResolverTest extends TemporaryDirectoryTestCase
{
    private const string PHP = "<?php\n";

    public function testExplicitFilesAreReturnedAsAbsolutePaths(): void
    {
        $this->write('src/A.php', self::PHP);

        self::assertSame([$this->dir.'/src/A.php'], $this->resolve(['src/A.php']));
    }

    public function testExplicitDirectoriesAreScannedRecursivelyAndSorted(): void
    {
        $this->write('src/b/B.php', self::PHP);
        $this->write('src/A.php', self::PHP);
        $this->write('src/readme.md', 'text');

        self::assertSame([$this->dir.'/src/A.php', $this->dir.'/src/b/B.php'], $this->resolve(['src']));
    }

    public function testMissingPathsNonPhpFilesAndDuplicatesAreDropped(): void
    {
        $this->write('src/A.php', self::PHP);
        $this->write('notes.txt', 'text');

        $files = $this->resolve(['nope.php', 'notes.txt', 'src/A.php', 'src', $this->dir.'/src/A.php']);

        self::assertSame([$this->dir.'/src/A.php'], $files);
    }

    public function testExcludedFilesAreDropped(): void
    {
        $this->write('src/A.php', self::PHP);
        $this->write('src/Generated/G.php', self::PHP);
        $config = Config::fromArray(['exclude' => ['src/Generated/*']], $this->dir);

        self::assertSame([$this->dir.'/src/A.php'], $this->resolve(['src'], $config));
    }

    public function testAllScansTheConfiguredSourceDirectoriesAndIgnoresMissingOnes(): void
    {
        $this->write('src/A.php', self::PHP);
        $this->write('lib/L.php', self::PHP);
        $this->write('other/O.php', self::PHP);
        $config = Config::fromArray(['source_dirs' => ['src', 'lib', 'gone']], $this->dir);

        $files = (new FileResolver($config, $this->dir))->resolve(new Options(Mode::All));

        self::assertSame([$this->dir.'/lib/L.php', $this->dir.'/src/A.php'], $files);
    }

    public function testAllAcceptsAbsoluteSourceDirectories(): void
    {
        $this->write('elsewhere/E.php', self::PHP);
        $config = Config::fromArray(['source_dirs' => [$this->dir.'/elsewhere']], $this->dir);

        $files = (new FileResolver($config, '/'))->resolve(new Options(Mode::All));

        self::assertSame([$this->dir.'/elsewhere/E.php'], $files);
    }

    public function testAllWithoutAnyExistingSourceDirectoryIsAnError(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('None of the configured source_dirs exists');

        (new FileResolver(Config::defaults($this->dir), $this->dir))->resolve(new Options(Mode::All));
    }

    public function testStagedFilesAreTheAddedAndModifiedPhpFiles(): void
    {
        $repo = new GitRepository($this->dir);
        $this->write('keep.php', self::PHP);
        $this->write('gone.php', "<?php\n// to be deleted\n");
        $repo->commitAll();

        $this->write('keep.php', "<?php\n// changed\n");
        $this->write('new.php', "<?php\n// brand new, unlike the deleted file\n");
        $this->write('notes.txt', 'text');
        $this->write('unstaged.php', self::PHP);
        unlink($this->dir.'/gone.php');
        $repo->git('add', 'keep.php', 'new.php', 'notes.txt', 'gone.php');

        self::assertSame([$this->dir.'/keep.php', $this->dir.'/new.php'], $this->resolveStaged());
    }

    public function testStagedFilesAreRelativeToTheProjectRootInASubdirectory(): void
    {
        $repo = new GitRepository($this->dir);
        $this->write('app/A.php', self::PHP);
        $this->write('other/B.php', self::PHP);
        $repo->git('add', '-A');

        $config = Config::defaults($this->dir.'/app');
        $files = (new FileResolver($config, $this->dir.'/app'))->resolve(new Options());

        self::assertSame([$this->dir.'/app/A.php'], $files);
    }

    public function testStagedFilesOutsideAGitRepositoryAreAnError(): void
    {
        $this->expectException(GitException::class);
        $this->expectExceptionMessage('git diff failed');

        $this->resolveStaged();
    }

    public function testBaseListsTheFilesChangedSinceTheMergeBase(): void
    {
        $repo = new GitRepository($this->dir);
        $this->write('old.php', self::PHP);
        $repo->commitAll();
        $repo->git('checkout', '-q', '-b', 'feature');
        $this->write('old.php', "<?php\n// changed\n");
        $this->write('added.php', self::PHP);
        $this->write('readme.md', 'text');
        $repo->commitAll();
        $repo->git('checkout', '-q', 'main');
        $this->write('later.php', self::PHP);
        $repo->commitAll();
        $repo->git('checkout', '-q', 'feature');

        $files = (new FileResolver(Config::defaults($this->dir), $this->dir))->resolve(new Options(Mode::Base, 'main'));

        self::assertSame([$this->dir.'/added.php', $this->dir.'/old.php'], $files);
    }

    public function testBaseWithAnUnknownRefIsAnError(): void
    {
        new GitRepository($this->dir);

        $this->expectException(GitException::class);
        $this->expectExceptionMessage('git diff failed');

        (new FileResolver(Config::defaults($this->dir), $this->dir))->resolve(new Options(Mode::Base, 'no-such-ref'));
    }

    public function testRefsThatCouldBeOptionsOrShellSyntaxAreRejectedBeforeGitRuns(): void
    {
        $git = new Git();

        foreach (['--output=/tmp/x', '-h', 'main; rm -rf /', 'a b', '$(id)', '', 'a`id`'] as $ref) {
            try {
                $git->changedFiles($this->dir, $ref);
                self::fail(sprintf('Ref "%s" should have been rejected.', $ref));
            } catch (GitException $e) {
                self::assertStringContainsString('is not a valid git ref', $e->getMessage());
            }
        }
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private function resolve(array $paths, ?Config $config = null): array
    {
        return (new FileResolver($config ?? Config::defaults($this->dir), $this->dir))->resolve(new Options(Mode::Paths, null, $paths));
    }

    /** @return list<string> */
    private function resolveStaged(): array
    {
        return (new FileResolver(Config::defaults($this->dir), $this->dir))->resolve(new Options());
    }
}
