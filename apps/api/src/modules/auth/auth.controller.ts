import { Body, Controller, Get, HttpCode, HttpStatus, Post, Req, Res, UseGuards } from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiResponse, ApiTags } from '@nestjs/swagger';
import { ConfigService } from '@nestjs/config';
import { JwtService } from '@nestjs/jwt';
import { Request, Response } from 'express';
import { CurrentUser, RequestUser } from '../../common/decorators/current-user.decorator';
import { AuthService } from './auth.service';
import { AuthResponseDto } from './dto/auth-response.dto';
import { LoginDto } from './dto/login.dto';
import { RegisterDto } from './dto/register.dto';
import { AcceptInviteDto } from './dto/accept-invite.dto';
import { JwtAuthGuard } from './guards/jwt-auth.guard';
import { JwtRefreshGuard } from './guards/jwt-refresh.guard';
import { EnvConfig } from '../../config/env.validation';

@ApiTags('Authentication')
@Controller('auth')
export class AuthController {
  constructor(
    private readonly authService: AuthService,
    // BUG-023 FIX: Inject ConfigService instead of reading process.env directly
    private readonly configService: ConfigService<EnvConfig, true>,
    // Used to decode the refresh token for revocation on logout
    private readonly jwtService: JwtService,
  ) {}

  @Post('register')
  @ApiOperation({ summary: 'Register a new user' })
  @ApiResponse({ status: HttpStatus.CREATED, type: AuthResponseDto })
  async register(@Body() dto: RegisterDto, @Res({ passthrough: true }) res: Response): Promise<AuthResponseDto> {
    const response = await this.authService.register(dto);
    this.setCookies(res, response);
    return response;
  }

  @Post('accept-invite')
  @HttpCode(HttpStatus.OK)
  @ApiOperation({ summary: 'Accept an invitation and set password' })
  @ApiResponse({ status: HttpStatus.OK, type: AuthResponseDto })
  async acceptInvite(@Body() dto: AcceptInviteDto, @Res({ passthrough: true }) res: Response): Promise<AuthResponseDto> {
    const response = await this.authService.acceptInvite(dto);
    this.setCookies(res, response);
    return response;
  }

  @Post('login')
  @HttpCode(HttpStatus.OK)
  @ApiOperation({ summary: 'Login with email and password' })
  @ApiResponse({ status: HttpStatus.OK, type: AuthResponseDto })
  async login(@Body() dto: LoginDto, @Req() req: Request, @Res({ passthrough: true }) res: Response): Promise<AuthResponseDto> {
    const response = await this.authService.login(dto, req.ip);
    this.setCookies(res, response);
    return response;
  }

  @UseGuards(JwtRefreshGuard)
  @Post('refresh')
  @HttpCode(HttpStatus.OK)
  @ApiOperation({ summary: 'Refresh access token' })
  @ApiResponse({ status: HttpStatus.OK, type: AuthResponseDto })
  async refresh(@Req() req: any, @Res({ passthrough: true }) res: Response): Promise<AuthResponseDto> {
    const response = await this.authService.refreshTokens(req.user.id, req.user.refreshToken);
    this.setCookies(res, response);
    return response;
  }

  @UseGuards(JwtAuthGuard)
  @Post('logout')
  @HttpCode(HttpStatus.NO_CONTENT)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Logout from current session' })
  async logout(@Req() req: any, @Res({ passthrough: true }) res: Response): Promise<void> {
    // BUG-016 FIX: Actually revoke the refresh token from Redis so it cannot be reused
    // even if someone has stolen the token before logout.
    if (req.cookies?.refreshToken) {
      try {
        const refreshSecret = this.configService.get('JWT_REFRESH_SECRET', { infer: true });
        const decoded = this.jwtService.verify<{ sub: string; jti: string }>(
          req.cookies.refreshToken,
          { secret: refreshSecret }
        );
        if (decoded?.sub && decoded?.jti) {
          await this.authService.logout(decoded.sub, decoded.jti);
        }
      } catch {
        // Token may be expired or malformed — still clear cookies
      }
    }
    this.clearCookies(res);
  }

  @UseGuards(JwtAuthGuard)
  @Post('logout-all')
  @HttpCode(HttpStatus.NO_CONTENT)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Logout from all sessions' })
  async logoutAll(@CurrentUser() user: RequestUser, @Res({ passthrough: true }) res: Response): Promise<void> {
    await this.authService.logoutAll(user.id);
    this.clearCookies(res);
  }

  @UseGuards(JwtAuthGuard)
  @Get('me')
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Get current user profile' })
  getProfile(@CurrentUser() user: RequestUser) {
    return user;
  }

  private setCookies(res: Response, response: AuthResponseDto): void {
    // BUG-023 FIX: Use ConfigService instead of process.env for validated config access
    const isProduction = this.configService.get('NODE_ENV', { infer: true }) === 'production';

    // Access Token Cookie (short-lived)
    res.cookie('accessToken', response.accessToken, {
      httpOnly: true,
      secure: isProduction,
      sameSite: isProduction ? 'strict' : 'lax',
      maxAge: response.expiresIn * 1000, // typically 15 minutes
    });

    // Refresh Token Cookie (long-lived)
    res.cookie('refreshToken', response.refreshToken, {
      httpOnly: true,
      secure: isProduction,
      sameSite: isProduction ? 'strict' : 'lax',
      maxAge: 7 * 24 * 60 * 60 * 1000, // 7 days
    });
  }

  private clearCookies(res: Response): void {
    res.clearCookie('accessToken');
    res.clearCookie('refreshToken');
  }
}
