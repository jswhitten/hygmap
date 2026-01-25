import { describe, it, expect, beforeEach, vi } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import type { ComponentProps } from "react";
import Toolbar from "./Toolbar";
import { useAppStore } from "../state/store";
import * as starsApi from "../api/stars";
import type { StarListResponse } from "../types/star";

// Mock the stars API
vi.mock("../api/stars", () => ({
  searchStars: vi.fn(),
}));

type ToolbarProps = ComponentProps<typeof Toolbar>;

const renderToolbar = (props: Partial<ToolbarProps> = {}) => {
  const defaultProps: ToolbarProps = {
    onHome: vi.fn(),
    onCenter: vi.fn(),
    getCameraState: vi.fn(() => ({
      position: [0, 0, 0] as [number, number, number],
      target: [0, 0, 0] as [number, number, number],
    })),
    getCanvas: vi.fn(() => document.createElement("canvas")),
  };

  return render(<Toolbar {...defaultProps} {...props} />);
};

const createSearchResponse = (results: StarListResponse["data"]): StarListResponse => ({
  result: "success",
  data: results,
  length: results.length,
});

const mockSearchResults = (results: StarListResponse["data"]) => {
  vi.mocked(starsApi.searchStars).mockResolvedValue(createSearchResponse(results));
};

describe("Toolbar Component", () => {
  beforeEach(() => {
    // Reset store
    useAppStore.setState({
      selectedStar: null,
      measure: { active: false, point1: null, point2: null },
      setSelectedStar: vi.fn(),
      setMeasureActive: vi.fn(),
      setMeasurePoint1: vi.fn(),
      setMeasurePoint2: vi.fn(),
    });

    // Reset API mocks
    vi.clearAllMocks();
  });

  describe("Rendering", () => {
    it("should render all toolbar buttons", () => {
      renderToolbar();

      expect(screen.getByRole("button", { name: /home/i })).toBeInTheDocument();
      expect(screen.getByRole("button", { name: /center/i })).toBeInTheDocument();
      expect(screen.getByRole("button", { name: /measure/i })).toBeInTheDocument();
      expect(screen.getByRole("button", { name: /copy link/i })).toBeInTheDocument();
      expect(screen.getByRole("button", { name: /screenshot/i })).toBeInTheDocument();
    });

    it("should render search input", () => {
      renderToolbar();

      const searchInput = screen.getByRole("textbox", { name: /search stars/i });
      expect(searchInput).toBeInTheDocument();
      expect(searchInput).toHaveAttribute("placeholder");
    });
  });

  describe("Home Button", () => {
    it("should have proper ARIA label", () => {
      renderToolbar();

      const button = screen.getByRole("button", { name: /home.*reset.*sol/i });
      expect(button).toHaveAttribute("aria-label");
    });

    it("should be clickable", () => {
      renderToolbar();

      const button = screen.getByRole("button", { name: /home/i });
      fireEvent.click(button);

      // Button should trigger home functionality (tested via integration)
      expect(button).toBeInTheDocument();
    });
  });

  describe("Center Button", () => {
    it("should be disabled when no star selected", () => {
      renderToolbar();

      const button = screen.getByRole("button", { name: /center.*no star selected/i });
      expect(button).toBeDisabled();
    });

    it("should be enabled when star is selected", () => {
      useAppStore.setState({
        selectedStar: {
          id: 1,
          x: 0,
          y: 0,
          z: 0,
          absmag: 5,
          display_name: "Test Star",
        },
      });

      renderToolbar();

      const button = screen.getByRole("button", { name: /center.*test star/i });
      expect(button).not.toBeDisabled();
    });
  });

  describe("Measure Button", () => {
    it("should toggle measure mode", () => {
      const setMeasureActive = vi.fn();
      useAppStore.setState({ setMeasureActive });

      renderToolbar();

      const button = screen.getByRole("button", { name: /measure distance/i });
      fireEvent.click(button);

      expect(setMeasureActive).toHaveBeenCalledWith(true);
    });

    it("should show exit measure mode when active", () => {
      useAppStore.setState({
        measure: { active: true, point1: null, point2: null },
      });

      renderToolbar();

      const button = screen.getByRole("button", { name: /exit measure mode/i });
      expect(button).toHaveAttribute("aria-pressed", "true");
    });
  });

  describe("Share Button", () => {
    it("should copy link to clipboard", async () => {
      // Mock clipboard API
      Object.assign(navigator, {
        clipboard: {
          writeText: vi.fn(() => Promise.resolve()),
        },
      });

      renderToolbar();

      const button = screen.getByRole("button", { name: /copy link/i });
      fireEvent.click(button);

      await waitFor(() => {
        expect(navigator.clipboard.writeText).toHaveBeenCalled();
      });
    });

    it("should show feedback after copying", async () => {
      Object.assign(navigator, {
        clipboard: {
          writeText: vi.fn(() => Promise.resolve()),
        },
      });

      renderToolbar();

      const button = screen.getByRole("button", { name: /copy link/i });
      fireEvent.click(button);

      await waitFor(() => {
        expect(screen.getByRole("button", { name: /link copied/i })).toBeInTheDocument();
      });
    });
  });

  describe("Screenshot Button", () => {
    it("should have proper ARIA label", () => {
      renderToolbar();

      const button = screen.getByRole("button", { name: /save screenshot|screenshot/i });
      expect(button).toHaveAttribute("aria-label");
    });

    it("should be clickable", () => {
      renderToolbar();

      const button = screen.getByRole("button", { name: /screenshot/i });
      fireEvent.click(button);

      // Screenshot functionality tested via integration
      expect(button).toBeInTheDocument();
    });
  });

  describe("Search Functionality", () => {
    it("should update search query as user types", () => {
      renderToolbar();

      const input = screen.getByRole("textbox", { name: /search stars/i });
      fireEvent.change(input, { target: { value: "Sirius" } });

      expect(input).toHaveValue("Sirius");
    });

    it("should call search API with debounce", async () => {
      const mockResults = [
        { id: 1, x: 0, y: 0, z: 0, absmag: -1.46, display_name: "Sirius", spect: "A1V" },
      ];
      mockSearchResults(mockResults);

      renderToolbar();

      const input = screen.getByRole("textbox", { name: /search stars/i });
      fireEvent.change(input, { target: { value: "Sir" } });

      await waitFor(
        () => {
    expect(starsApi.searchStars).toHaveBeenCalledWith("Sir", 10);
        },
        { timeout: 600 }
      );
    });

    it("should not search with query less than 2 characters", async () => {
      renderToolbar();

      const input = screen.getByRole("textbox", { name: /search stars/i });
      fireEvent.change(input, { target: { value: "S" } });

      await waitFor(
        () => {
          expect(starsApi.searchStars).not.toHaveBeenCalled();
        },
        { timeout: 600 }
      );
    });

    it("should display search results", async () => {
      const mockResults = [
        { id: 1, x: 0, y: 0, z: 0, absmag: -1.46, display_name: "Sirius", spect: "A1V" },
      ];
      mockSearchResults(mockResults);

      renderToolbar();

      const input = screen.getByRole("textbox", { name: /search stars/i });
      fireEvent.change(input, { target: { value: "Sirius" } });

      await waitFor(() => {
        const listbox = screen.getByRole("listbox", { name: /search results/i });
        expect(listbox).toBeInTheDocument();
      });
    });

    it("should select star from search results", async () => {
      const mockResults = [
        { id: 1, x: 0, y: 0, z: 0, absmag: -1.46, display_name: "Sirius", spect: "A1V" },
      ];
      mockSearchResults(mockResults);

      const setSelectedStar = vi.fn();
      useAppStore.setState({ setSelectedStar });

      renderToolbar();

      const input = screen.getByRole("textbox", { name: /search stars/i });
      fireEvent.change(input, { target: { value: "Sirius" } });

      await waitFor(() => {
        const option = screen.getByRole("option", { name: /sirius/i });
        fireEvent.click(option);
      });

      expect(setSelectedStar).toHaveBeenCalledWith(
        expect.objectContaining({
          display_name: "Sirius",
        })
      );
    });

    it("should clear search results on blur", async () => {
      const mockResults = [
        { id: 1, x: 0, y: 0, z: 0, absmag: -1.46, display_name: "Sirius", spect: "A1V" },
      ];
      mockSearchResults(mockResults);

      renderToolbar();

      const input = screen.getByRole("textbox", { name: /search stars/i });
      fireEvent.change(input, { target: { value: "Sirius" } });

      await waitFor(() => {
        expect(screen.getByRole("listbox")).toBeInTheDocument();
      });

      fireEvent.blur(input);

      await waitFor(() => {
        expect(screen.queryByRole("listbox")).not.toBeInTheDocument();
      });
    });
  });

  describe("Accessibility", () => {
    it("should have proper ARIA attributes on search input", () => {
      renderToolbar();

      const input = screen.getByRole("textbox", { name: /search stars/i });
      expect(input).toHaveAttribute("aria-label");
      expect(input).toHaveAttribute("aria-autocomplete", "list");
    });

    it("should announce search results to screen readers", async () => {
      const mockResults = [
        { id: 1, x: 0, y: 0, z: 0, absmag: -1.46, display_name: "Sirius", spect: "A1V" },
      ];
      mockSearchResults(mockResults);

      renderToolbar();

      const input = screen.getByRole("textbox", { name: /search stars/i });
      fireEvent.change(input, { target: { value: "Sirius" } });

      await waitFor(() => {
        const listbox = screen.getByRole("listbox");
        expect(listbox).toHaveAttribute("aria-label", "Search results");
      });
    });
  });
});
